<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\EventApplication;
use App\Models\EventCredential;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffProfileChangeRequest;
use App\Models\TeamMembership;
use App\Models\Waiver;

/**
 * The transactional notifications Meridian sends, and nothing else (M18.21;
 * NOTIFY-001, NOTIFY-004, NOTIFY-010).
 *
 * The set is closed on purpose. NOTIFY-001 names the operational events that
 * change what a person may do and that the person would otherwise have no
 * reason to check for, and NOTIFY-010 puts preferences, digests, opt-out
 * categories, and a notification history surface out of scope — so there is no
 * category system here for a caller to invent a category in. Adding a
 * notification means adding a case, which means adding a template and a
 * requirement to point at.
 *
 * Every case names the model its notification is *about*. That model is the
 * whole of what a delivery record needs to store, because the message is
 * rebuilt from it at send time rather than frozen when the operation happened:
 * a body kept on the row would be a message body retained in the trail, which
 * NOTIFY-007 refuses, and it would also be the version of events from before
 * the sync, the retry, or the correction that followed.
 */
enum NotificationType: string
{
    case ApplicationApproved = 'application.approved';

    case ApplicationRejected = 'application.rejected';

    case ApplicationDeferred = 'application.deferred';

    /**
     * First assignment into a department, naming the teams it came with.
     *
     * NOTIFY-001A collapses the pair: a staff member cannot belong to a
     * department without belonging to a team (VOL-006), so the two always
     * happen together on first assignment and two emails would describe one
     * action. The collapse is structural rather than a de-duplication window —
     * department assignment creates its team memberships in the same
     * transaction, and the separate team-addition path refuses to run without
     * an existing department membership.
     */
    case DepartmentMembershipAdded = 'membership.department_added';

    /** A later team addition inside a department the staff member already belongs to. */
    case TeamMembershipAdded = 'membership.team_added';

    case DocumentAcknowledgmentOutstanding = 'document.acknowledgment_outstanding';

    /**
     * A required waiver that is outstanding or has expired.
     *
     * One case rather than two because NOTIFY-001 lists it as one item and the
     * recipient's next action is the same either way: complete the waiver. The
     * message says which of the two it is, since "has expired" and "was never
     * completed" are different news to somebody who remembers signing it.
     */
    case WaiverOutstanding = 'waiver.outstanding';

    case CredentialEligibilityBlocked = 'credential.eligibility_blocked';

    case ShiftAssignmentRemoved = 'shift.assignment_removed';

    case ShiftCancelled = 'shift.cancelled';

    /**
     * A decision on a profile change request (VOL-025).
     *
     * Not in the NOTIFY-001 list, which was written before the profile change
     * requests of VOL-019 through VOL-029 existed. M18.20D carries it and
     * deferred it to this task for want of a delivery path; the reviewer's
     * reason travels with a rejection.
     */
    case ProfileChangeRequestDecided = 'profile_change_request.decided';

    /**
     * The model class this notification's subject record is an instance of.
     *
     * @return class-string
     */
    public function subjectClass(): string
    {
        return match ($this) {
            self::ApplicationApproved,
            self::ApplicationRejected,
            self::ApplicationDeferred => EventApplication::class,
            self::DepartmentMembershipAdded => DepartmentMembership::class,
            self::TeamMembershipAdded => TeamMembership::class,
            self::DocumentAcknowledgmentOutstanding => DocumentAcknowledgmentRequirement::class,
            self::WaiverOutstanding => Waiver::class,
            self::CredentialEligibilityBlocked => EventCredential::class,
            self::ShiftAssignmentRemoved => ShiftAssignment::class,
            self::ShiftCancelled => Shift::class,
            self::ProfileChangeRequestDecided => StaffProfileChangeRequest::class,
        };
    }

    /**
     * The client path the recipient can act on this notification at
     * (NOTIFY-004).
     *
     * A path rather than a URL, because the host a notification links to is
     * node configuration and a value frozen here would outlive the deployment
     * that was true for. Following the link does not bypass authentication:
     * every path below is behind the session the client establishes, and the
     * node refuses the reads underneath them regardless of what was linked.
     */
    public function actionPath(): string
    {
        return match ($this) {
            // An applicant may hold no account at all, so the only surface that
            // is theirs to reach is the one that makes them somebody. The
            // applicant portal of M18.22 is where this points once it exists.
            self::ApplicationApproved,
            self::ApplicationRejected,
            self::ApplicationDeferred => '/login',
            self::DepartmentMembershipAdded,
            self::TeamMembershipAdded,
            self::CredentialEligibilityBlocked => '/staff/me',
            self::DocumentAcknowledgmentOutstanding => '/staff/acknowledgments',
            // The shift board is where a required waiver is named against the
            // shift requiring it, which is the fact a staff member needs in
            // order to know what completing it buys them.
            self::WaiverOutstanding,
            self::ShiftAssignmentRemoved,
            self::ShiftCancelled => '/staff/shifts',
            self::ProfileChangeRequestDecided => '/staff/me/requests',
        };
    }

    /** What the link is called in the message. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::ApplicationApproved,
            self::ApplicationRejected,
            self::ApplicationDeferred => 'Sign in to Meridian',
            self::DepartmentMembershipAdded,
            self::TeamMembershipAdded,
            self::CredentialEligibilityBlocked => 'Open your staff record',
            self::DocumentAcknowledgmentOutstanding => 'Open your acknowledgments',
            self::WaiverOutstanding,
            self::ShiftAssignmentRemoved,
            self::ShiftCancelled => 'Open your shifts',
            self::ProfileChangeRequestDecided => 'Open your requests',
        };
    }

    /**
     * The operation type an on-site node records to hand this notification to
     * central (NOTIFY-008).
     *
     * Command-style, because a node operation's meaning must live in its type
     * together with the entity it names: `payload_json` is unauthenticated and
     * is never applied (data/API 13.3).
     */
    public function operationType(): string
    {
        return 'notification.send.'.$this->value;
    }

    public static function fromOperationType(string $operationType): ?self
    {
        if (! str_starts_with($operationType, 'notification.send.')) {
            return null;
        }

        return self::tryFrom(substr($operationType, strlen('notification.send.')));
    }
}
