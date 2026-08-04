<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventCredential;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\TeamMembership;
use App\Models\Waiver;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\Lettermark;
use App\Services\Branding\SystemMailIdentity;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Credential\CredentialStatusReasons;
use App\Services\Notifications\NotificationSubjectMissingException as SubjectMissing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Turns a delivery record back into the message it stands for (NOTIFY-003,
 * NOTIFY-004).
 *
 * Composition happens at send time from the subject record rather than at
 * dispatch time into a stored body, for two reasons that both point the same
 * way. NOTIFY-007 keeps message bodies out of the retained trail, so there is
 * nowhere to put one; and a notification that crossed a sync from an on-site
 * node describes a record central holds its own copy of, so rebuilding is what
 * makes the two nodes say the same thing.
 *
 * Prose here is operational and names the event, organization, and department
 * the notification concerns (NOTIFY-004). It never names a status the
 * recipient is not entitled to know: NOTIFY-002 keeps Do Not Staff out of
 * notification text entirely, which is why a blocked credential reports the
 * blocking reason only where that reason is the recipient's own outstanding
 * work.
 */
class NotificationComposer
{
    public function __construct(private readonly NotificationLinkBuilder $links) {}

    /**
     * @throws SubjectMissing when the record the notification is about is gone
     */
    public function compose(NotificationDelivery $delivery): NotificationMessage
    {
        $type = $delivery->type();

        if ($type === null) {
            throw SubjectMissing::unknownType((string) $delivery->notification_type);
        }

        $subject = $delivery->subject();

        if (! $subject instanceof Model) {
            throw SubjectMissing::forDelivery($delivery);
        }

        $organization = $delivery->organization;

        return match ($type) {
            NotificationType::ApplicationApproved,
            NotificationType::ApplicationRejected,
            NotificationType::ApplicationDeferred => $this->applicationDecision($type, $subject, $organization),
            NotificationType::DepartmentMembershipAdded => $this->departmentAddition($type, $subject, $organization),
            NotificationType::TeamMembershipAdded => $this->teamAddition($type, $subject, $organization),
            NotificationType::DocumentAcknowledgmentOutstanding => $this->acknowledgmentOutstanding($type, $subject, $organization),
            NotificationType::WaiverOutstanding => $this->waiverOutstanding($type, $subject, $organization, $delivery->recipientStaff),
            NotificationType::CredentialEligibilityBlocked => $this->credentialBlocked($type, $subject, $organization),
            NotificationType::ShiftAssignmentRemoved => $this->shiftAssignmentRemoved($type, $subject, $organization),
            NotificationType::ShiftCancelled => $this->shiftCancelled($type, $subject, $organization),
            NotificationType::ProfileChangeRequestDecided => $this->profileChangeDecided($type, $subject, $organization),
        };
    }

    private function applicationDecision(
        NotificationType $type,
        EventApplication $application,
        ?Organization $organization,
    ): NotificationMessage {
        $application->loadMissing(['event', 'organization']);
        $organization ??= $application->organization;
        $organizationName = $this->organizationName($organization);
        $eventName = (string) ($application->event?->name ?? 'the event');

        $facts = [
            'Organization' => $organizationName,
            'Event' => $eventName,
        ];

        $reason = trim((string) $application->decision_reason);

        if ($reason !== '') {
            $facts['Reason given'] = $reason;
        }

        [$heading, $paragraphs] = match ($type) {
            NotificationType::ApplicationApproved => [
                'Your application was approved',
                [
                    sprintf('%s has approved your application to staff %s.', $organizationName, $eventName),
                    'Sign in to Meridian to complete anything still outstanding — document acknowledgments, waivers, and shift signups all live on your staff record.',
                ],
            ],
            NotificationType::ApplicationRejected => [
                'Your application was not approved',
                [
                    sprintf('%s has reviewed your application to staff %s and has not approved it.', $organizationName, $eventName),
                    'No further action is needed. Contact the organization directly if you want to discuss the decision.',
                ],
            ],
            default => [
                'Your application was deferred',
                [
                    sprintf('%s has deferred a decision on your application to staff %s.', $organizationName, $eventName),
                    'Your application stays open. You will hear again when the organization reaches a decision.',
                ],
            ],
        };

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: %s', $organizationName, $heading),
            heading: $heading,
            paragraphs: $paragraphs,
            facts: $facts,
        );
    }

    private function departmentAddition(
        NotificationType $type,
        DepartmentMembership $membership,
        ?Organization $organization,
    ): NotificationMessage {
        $membership->loadMissing(['department.organization', 'teamMemberships.team']);
        $department = $membership->department;
        $organization ??= $department?->organization;
        $organizationName = $this->organizationName($organization);
        $departmentName = (string) ($department?->name ?? 'a department');

        $teamNames = $membership->teamMemberships
            ->map(fn (TeamMembership $teamMembership): string => (string) ($teamMembership->team?->name ?? ''))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();

        $facts = [
            'Organization' => $organizationName,
            'Department' => $departmentName,
        ];

        // NOTIFY-001A: the pair is one message naming both, because a staff
        // member cannot belong to a department without belonging to a team.
        if ($teamNames !== []) {
            $facts[count($teamNames) === 1 ? 'Team' : 'Teams'] = implode(', ', $teamNames);
        }

        $sentence = $teamNames === []
            ? sprintf('You have been added to %s at %s.', $departmentName, $organizationName)
            : sprintf(
                'You have been added to %s at %s, on %s.',
                $departmentName,
                $organizationName,
                implode(' and ', $teamNames),
            );

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: you were added to %s', $organizationName, $departmentName),
            heading: sprintf('You were added to %s', $departmentName),
            paragraphs: [
                $sentence,
                'Your staff record shows the shifts you can sign up for and anything the department still needs from you.',
            ],
            facts: $facts,
            department: $department,
        );
    }

    private function teamAddition(
        NotificationType $type,
        TeamMembership $membership,
        ?Organization $organization,
    ): NotificationMessage {
        $membership->loadMissing(['team.department.organization']);
        $team = $membership->team;
        $department = $team?->department;
        $organization ??= $department?->organization;
        $organizationName = $this->organizationName($organization);
        $teamName = (string) ($team?->name ?? 'a team');
        $departmentName = (string) ($department?->name ?? 'your department');

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: you were added to %s', $organizationName, $teamName),
            heading: sprintf('You were added to %s', $teamName),
            paragraphs: [
                sprintf('You have been added to %s, in %s at %s.', $teamName, $departmentName, $organizationName),
                'Team membership can change which shifts you are eligible for, so it is worth a look at your staff record.',
            ],
            facts: [
                'Organization' => $organizationName,
                'Department' => $departmentName,
                'Team' => $teamName,
            ],
            department: $department,
        );
    }

    private function acknowledgmentOutstanding(
        NotificationType $type,
        DocumentAcknowledgmentRequirement $requirement,
        ?Organization $organization,
    ): NotificationMessage {
        $requirement->loadMissing(['organization', 'document', 'departmentScope']);
        $organization ??= $requirement->organization;
        $organizationName = $this->organizationName($organization);
        $documentTitle = (string) ($requirement->document?->title ?? 'a required document');
        $department = $requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT
            ? $requirement->departmentScope
            : null;

        $facts = [
            'Organization' => $organizationName,
            'Document' => $documentTitle,
        ];

        if ($department instanceof Department) {
            $facts['Department'] = (string) $department->name;
        }

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: %s needs your acknowledgment', $organizationName, $documentTitle),
            heading: 'A required document is waiting for you',
            paragraphs: [
                sprintf(
                    '%s requires you to read and acknowledge %s.',
                    $organizationName,
                    $documentTitle,
                ),
                'Acknowledgments are recorded against the version you read, so an outstanding one does not clear itself when the document changes.',
            ],
            facts: $facts,
            department: $department,
        );
    }

    private function waiverOutstanding(
        NotificationType $type,
        Waiver $waiver,
        ?Organization $organization,
        ?Staff $staff,
    ): NotificationMessage {
        $waiver->loadMissing('organization');
        $organization ??= $waiver->organization;
        $organizationName = $this->organizationName($organization);
        $waiverName = (string) ($waiver->name ?? 'a required waiver');

        // Expired and never completed both leave the waiver outstanding, and
        // both send the recipient to the same place, but they are different
        // news to somebody who remembers signing it — so the message says
        // which, read from the completions rather than from a stored flag that
        // could describe a state that has since changed.
        $hasLapsedCompletion = $staff instanceof Staff
            && $waiver->completions()->where('staff_id', $staff->getKey())->exists()
            && ! $waiver->isCompleteFor($staff);

        $heading = $hasLapsedCompletion
            ? sprintf('%s has expired', $waiverName)
            : sprintf('%s is outstanding', $waiverName);

        $paragraphs = $hasLapsedCompletion
            ? [
                sprintf('The waiver you completed for %s at %s has passed its expiration and no longer counts as complete.', $waiverName, $organizationName),
                'Completing it again restores your eligibility for the shifts that require it.',
            ]
            : [
                sprintf('%s requires %s from you, and it has not been completed.', $organizationName, $waiverName),
                'Shifts that require this waiver will not credential you until it is complete.',
            ];

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: %s', $organizationName, $heading),
            heading: $heading,
            paragraphs: $paragraphs,
            facts: [
                'Organization' => $organizationName,
                'Waiver' => $waiverName,
                'Status' => $hasLapsedCompletion ? 'Expired' : 'Not completed',
            ],
        );
    }

    private function credentialBlocked(
        NotificationType $type,
        EventCredential $credential,
        ?Organization $organization,
    ): NotificationMessage {
        $credential->loadMissing(['event.organization']);
        $event = $credential->event;
        $organization ??= $event?->organization;
        $organizationName = $this->organizationName($organization);
        $eventName = (string) ($event?->name ?? 'the event');

        $facts = [
            'Organization' => $organizationName,
            'Event' => $eventName,
        ];

        // Only reasons that describe the recipient's own outstanding work are
        // named. An organization blocking status is not one of them:
        // NOTIFY-002 forbids a notification disclosing Do Not Staff, and a
        // label that named it would do exactly that.
        $reasonLabel = $this->disclosableCredentialReason($credential);

        if ($reasonLabel !== null) {
            $facts['Reason'] = $reasonLabel;
        }

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: your credential for %s is blocked', $organizationName, $eventName),
            heading: 'Your credential is blocked',
            paragraphs: [
                sprintf('You are not currently credentialed for %s at %s.', $eventName, $organizationName),
                $reasonLabel !== null
                    ? 'Your staff record shows what is outstanding, and the block clears once it is resolved.'
                    : 'Your staff record shows the current state. Contact the organization if it is not clear what is needed.',
            ],
            facts: $facts,
            event: $event,
        );
    }

    private function shiftAssignmentRemoved(
        NotificationType $type,
        ShiftAssignment $assignment,
        ?Organization $organization,
    ): NotificationMessage {
        $assignment->loadMissing(['shift.event.organization', 'shift.department']);
        $shift = $assignment->shift;
        $event = $shift?->event;
        $organization ??= $event?->organization;
        $organizationName = $this->organizationName($organization);
        $shiftTitle = (string) ($shift?->title ?? 'a shift');

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: you were removed from %s', $organizationName, $shiftTitle),
            heading: sprintf('You were removed from %s', $shiftTitle),
            paragraphs: [
                sprintf('A lead has removed you from %s.', $shiftTitle),
                'You are not expected on this shift. Your shift board shows what you are still signed up for.',
            ],
            facts: array_filter([
                'Organization' => $organizationName,
                'Event' => $event?->name !== null ? (string) $event->name : null,
                'Department' => $this->departmentNameFor($shift),
                'Shift' => $shiftTitle,
                'Scheduled' => $this->shiftWindow($shift),
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
            department: $shift?->department,
            event: $event,
        );
    }

    private function shiftCancelled(
        NotificationType $type,
        Shift $shift,
        ?Organization $organization,
    ): NotificationMessage {
        $shift->loadMissing(['event.organization', 'department']);
        $event = $shift->event;
        $organization ??= $event?->organization;
        $organizationName = $this->organizationName($organization);
        $shiftTitle = (string) ($shift->title ?? 'a shift');

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf('%s: %s was cancelled', $organizationName, $shiftTitle),
            heading: sprintf('%s was cancelled', $shiftTitle),
            paragraphs: [
                sprintf('%s has cancelled %s. You were signed up for it.', $organizationName, $shiftTitle),
                'Nobody is expected on this shift. Your shift board shows what else is open.',
            ],
            facts: array_filter([
                'Organization' => $organizationName,
                'Event' => $event?->name !== null ? (string) $event->name : null,
                'Department' => $this->departmentNameFor($shift),
                'Shift' => $shiftTitle,
                'Was scheduled' => $this->shiftWindow($shift),
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
            department: $shift->department,
            event: $event,
        );
    }

    private function profileChangeDecided(
        NotificationType $type,
        StaffProfileChangeRequest $request,
        ?Organization $organization,
    ): NotificationMessage {
        $request->loadMissing('organization');
        $organization ??= $request->organization;
        $organizationName = $this->organizationName($organization);

        $kindLabel = $request->kind === StaffProfileChangeRequest::KIND_HANDLE
            ? 'handle change'
            : 'profile picture';
        $approved = $request->status === StaffProfileChangeRequest::STATUS_APPROVED;

        $facts = [
            'Organization' => $organizationName,
            'Request' => ucfirst($kindLabel),
            'Decision' => $approved ? 'Approved' : 'Rejected',
        ];

        if ($request->kind === StaffProfileChangeRequest::KIND_HANDLE
            && trim((string) $request->requested_handle) !== '') {
            $facts['Requested handle'] = (string) $request->requested_handle;
        }

        // VOL-025: the reviewer's reason travels with a rejection. It is the
        // whole reason the notification is worth sending — a rejection with no
        // reason leaves the submitter to guess what to change.
        $reason = trim((string) $request->decision_reason);

        if (! $approved && $reason !== '') {
            $facts['Reason given'] = $reason;
        }

        return $this->message(
            type: $type,
            organization: $organization,
            subject: sprintf(
                '%s: your %s was %s',
                $organizationName,
                $kindLabel,
                $approved ? 'approved' : 'rejected',
            ),
            heading: sprintf('Your %s was %s', $kindLabel, $approved ? 'approved' : 'rejected'),
            paragraphs: [
                $approved
                    ? sprintf('A reviewer at %s approved your %s, and it is now in force.', $organizationName, $kindLabel)
                    : sprintf('A reviewer at %s did not approve your %s.', $organizationName, $kindLabel),
                'Your requests page shows the full history, including anything still outstanding.',
            ],
            facts: $facts,
        );
    }

    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, string>  $facts
     */
    private function message(
        NotificationType $type,
        ?Organization $organization,
        string $subject,
        string $heading,
        array $paragraphs,
        array $facts,
        ?Department $department = null,
        ?Event $event = null,
    ): NotificationMessage {
        $identity = $organization instanceof Organization
            ? SystemMailIdentity::forOrganization($organization)
            : SystemMailIdentity::meridian();

        $profile = $organization instanceof Organization
            ? BrandingProfile::forOrganization($organization)
            : BrandingProfile::meridian();

        return new NotificationMessage(
            type: $type,
            identity: $identity,
            // An organization that uploaded a logo but never opened the colour
            // pickers keeps Meridian's palette, which is the same distinction
            // BrandingProfile draws for every other branded surface.
            palette: $profile->hasCustomPalette ? $profile->palette : BrandingPalette::meridianDefault(),
            markUrl: $this->markUrlFor($profile),
            lettermark: Lettermark::forName($identity->displayName),
            subject: $subject,
            heading: $heading,
            paragraphs: $paragraphs,
            facts: $facts,
            actionLabel: $type->actionLabel(),
            actionUrl: $this->links->urlFor($type),
        );
    }

    private function markUrlFor(BrandingProfile $profile): ?string
    {
        $attachmentId = $profile->compactMarkAttachmentId ?? $profile->fullLockupAttachmentId;

        if ($attachmentId === null) {
            return null;
        }

        return route('branding.asset', ['attachment' => $attachmentId]);
    }

    private function organizationName(?Organization $organization): string
    {
        if (! $organization instanceof Organization) {
            return 'Meridian';
        }

        return SystemMailIdentity::forOrganization($organization)->displayName;
    }

    private function departmentNameFor(?Shift $shift): ?string
    {
        if (! $shift instanceof Shift) {
            return null;
        }

        $name = $shift->department?->name ?? $shift->department_name_snapshot;

        return $name !== null ? (string) $name : null;
    }

    /**
     * The shift's scheduled window in the event's own timezone, because a
     * recipient reading this on a phone in another timezone needs the time the
     * shift is called at rather than the time their handset would render.
     */
    private function shiftWindow(?Shift $shift): ?string
    {
        if (! $shift instanceof Shift || ! $shift->starts_at instanceof Carbon) {
            return null;
        }

        $timezone = (string) ($shift->event?->timezone ?: config('app.timezone'));
        $start = $shift->starts_at->copy()->timezone($timezone);

        if (! $shift->ends_at instanceof Carbon) {
            return sprintf('%s (%s)', $start->format('D j M Y, H:i'), $timezone);
        }

        $end = $shift->ends_at->copy()->timezone($timezone);

        return sprintf(
            '%s to %s (%s)',
            $start->format('D j M Y, H:i'),
            $start->isSameDay($end) ? $end->format('H:i') : $end->format('D j M Y, H:i'),
            $timezone,
        );
    }

    private function disclosableCredentialReason(EventCredential $credential): ?string
    {
        $reason = (string) $credential->status_reason;

        $disclosable = [
            CredentialEligibilityService::REASON_NO_SIGNED_UP_SHIFTS,
            CredentialEligibilityService::REASON_MISSING_REQUIRED_WAIVER,
            CredentialEligibilityService::REASON_AGE_REQUIREMENT_NOT_SATISFIED,
            CredentialEligibilityService::REASON_MISSING_DATE_OF_BIRTH,
            CredentialEligibilityService::REASON_DEPARTMENT_INELIGIBLE,
        ];

        if (! in_array($reason, $disclosable, true)) {
            return null;
        }

        return CredentialStatusReasons::label($reason);
    }
}
