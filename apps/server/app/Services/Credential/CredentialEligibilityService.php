<?php

namespace App\Services\Credential;

use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\NotificationType;
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Event credential eligibility calculation (CRED-001 through CRED-010, CRED-014; WAIVER-006).
 *
 * Manual revocation is delivered by {@see CredentialRevocationService}.
 */
class CredentialEligibilityService
{
    public const REASON_NO_SIGNED_UP_SHIFTS = 'no_signed_up_shifts';

    public const REASON_ORGANIZATION_BLOCKING_STATUS = 'organization_blocking_status';

    public const REASON_DEPARTMENT_INELIGIBLE = 'department_ineligible';

    public const REASON_MISSING_REQUIRED_WAIVER = 'missing_required_waiver';

    public const REASON_AGE_REQUIREMENT_NOT_SATISFIED = 'age_requirement_not_satisfied';

    public const REASON_MISSING_DATE_OF_BIRTH = 'missing_date_of_birth';

    public function __construct(
        private readonly StaffStatusService $staffStatus,
        private readonly NotificationDispatcher $notifications,
        private readonly NotificationRecipientResolver $notificationRecipients,
    ) {}

    /**
     * Evaluate whether the staff member satisfies credential requirements for the event.
     */
    public function evaluate(Event $event, Staff $staff, ?Carbon $asOf = null): CredentialEvaluation
    {
        $asOf ??= Carbon::now();
        $event->loadMissing('organization');

        $credentialAssignments = $this->credentialEligibleAssignmentsForEvent($event, $staff);

        if ($credentialAssignments->isEmpty()) {
            return CredentialEvaluation::blocked(self::REASON_NO_SIGNED_UP_SHIFTS);
        }

        $organizationStatus = StaffOrganizationStatus::query()
            ->where('organization_id', $event->organization_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
            return CredentialEvaluation::blocked(self::REASON_ORGANIZATION_BLOCKING_STATUS);
        }

        $workedDepartmentIds = $credentialAssignments
            ->pluck('shift.department_id')
            ->unique()
            ->values();

        foreach ($workedDepartmentIds as $departmentId) {
            $departmentMembership = DepartmentMembership::query()
                ->active()
                ->where('department_id', $departmentId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($departmentMembership === null) {
                continue;
            }

            if ($this->staffStatus->effectiveDepartmentStatus($departmentMembership) === DepartmentMembership::STATUS_INELIGIBLE) {
                return CredentialEvaluation::blocked(self::REASON_DEPARTMENT_INELIGIBLE);
            }
        }

        $requiredWaivers = $this->requiredWaiversForAssignments($credentialAssignments);

        foreach ($requiredWaivers as $waiver) {
            if (! $waiver->isCompleteFor($staff, $asOf)) {
                return CredentialEvaluation::blocked(self::REASON_MISSING_REQUIRED_WAIVER);
            }
        }

        $ageEvaluation = $this->evaluateAgeRequirement($event, $staff, $asOf);

        if (! $ageEvaluation->eligible) {
            return $ageEvaluation;
        }

        return CredentialEvaluation::eligible();
    }

    /**
     * Recalculate and persist credential state for a staff member and event.
     *
     * Returns null when the staff member has never held event shifts and no credential record exists.
     */
    public function recalculate(
        Event $event,
        Staff $staff,
        ?Carbon $asOf = null,
        ?User $changedBy = null,
    ): ?EventCredential {
        $asOf ??= Carbon::now();

        $credential = EventCredential::query()
            ->where('event_id', $event->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($credential?->isRevoked()) {
            return $credential;
        }

        $credentialAssignments = $this->credentialEligibleAssignmentsForEvent($event, $staff);

        if ($credentialAssignments->isEmpty()) {
            if ($credential === null) {
                return null;
            }

            return $this->persistCredentialState(
                credential: $credential,
                status: EventCredential::STATUS_BLOCKED,
                reason: self::REASON_NO_SIGNED_UP_SHIFTS,
                changedBy: $changedBy,
            );
        }

        $evaluation = $this->evaluate($event, $staff, $asOf);

        return $this->persistCredentialState(
            credential: $credential ?? new EventCredential([
                'event_id' => $event->id,
                'staff_id' => $staff->id,
            ]),
            status: $evaluation->eligible
                ? EventCredential::STATUS_ELIGIBLE
                : EventCredential::STATUS_BLOCKED,
            reason: $evaluation->blockReason,
            changedBy: $changedBy,
        );
    }

    /**
     * Whether an active assignment counts toward credential eligibility (CRED-004, CRED-014).
     *
     * Self-signup always counts. Lead assignments created before the shift starts count
     * as planned coverage. Assignments created once shift operations have begun are
     * unscheduled work and do not retroactively grant credential eligibility.
     */
    public function countsTowardCredentialEligibility(ShiftAssignment $assignment): bool
    {
        $assignment->loadMissing('shift');
        $shift = $assignment->shift;

        if ($shift === null) {
            return false;
        }

        if ($assignment->assignment_status === ShiftAssignment::STATUS_SIGNED_UP) {
            return true;
        }

        return $assignment->created_at !== null
            && $shift->starts_at !== null
            && $assignment->created_at->lt($shift->starts_at);
    }

    /**
     * @return Collection<int, ShiftAssignment>
     */
    private function credentialEligibleAssignmentsForEvent(Event $event, Staff $staff): Collection
    {
        return $this->activeAssignmentsForEvent($event, $staff)
            ->filter(fn (ShiftAssignment $assignment): bool => $this->countsTowardCredentialEligibility($assignment))
            ->values();
    }

    /**
     * @return Collection<int, ShiftAssignment>
     */
    private function activeAssignmentsForEvent(Event $event, Staff $staff): Collection
    {
        return ShiftAssignment::query()
            ->active()
            ->where('staff_id', $staff->id)
            ->whereHas('shift', function ($query) use ($event): void {
                $query->where('event_id', $event->id);
            })
            ->with(['shift.requiredWaivers'])
            ->get();
    }

    /**
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @return Collection<int, Waiver>
     */
    private function requiredWaiversForAssignments(Collection $assignments): Collection
    {
        return $assignments
            ->flatMap(fn (ShiftAssignment $assignment): Collection => $assignment->shift?->requiredWaivers ?? collect())
            ->unique('id')
            ->values();
    }

    private function evaluateAgeRequirement(Event $event, Staff $staff, Carbon $asOf): CredentialEvaluation
    {
        if ($event->minimum_staff_age === null) {
            return CredentialEvaluation::eligible();
        }

        if ($staff->date_of_birth === null) {
            return CredentialEvaluation::blocked(self::REASON_MISSING_DATE_OF_BIRTH);
        }

        $eventDate = $this->eventDate($event, $asOf);
        $ageOnEventDate = $staff->date_of_birth->diffInYears($eventDate);

        if ($ageOnEventDate < $event->minimum_staff_age) {
            return CredentialEvaluation::blocked(self::REASON_AGE_REQUIREMENT_NOT_SATISFIED);
        }

        return CredentialEvaluation::eligible();
    }

    private function eventDate(Event $event, Carbon $asOf): Carbon
    {
        $timezone = $event->timezone ?: config('app.timezone');

        if ($event->starts_at !== null) {
            return $event->starts_at->copy()->timezone($timezone)->startOfDay();
        }

        return $asOf->copy()->timezone($timezone)->startOfDay();
    }

    private function persistCredentialState(
        EventCredential $credential,
        string $status,
        ?string $reason,
        ?User $changedBy,
    ): EventCredential {
        $statusChanged = ! $credential->exists || $credential->status !== $status;

        $credential->forceFill([
            'status' => $status,
            'status_reason' => $reason,
            'changed_by_user_id' => $statusChanged ? $changedBy?->id : $credential->changed_by_user_id,
        ])->save();

        $credential = $credential->refresh();

        if ($statusChanged && $status === EventCredential::STATUS_BLOCKED) {
            $this->notifyBlocked($credential, $changedBy);
        }

        return $credential;
    }

    /**
     * Tell the staff member their credential is blocked (NOTIFY-001).
     *
     * Only on the transition into blocked. Recalculation runs on every shift
     * signup, removal, waiver completion, and status change, and most runs
     * leave the credential exactly where it was — notifying on each would mail
     * somebody daily about a fact that has not changed since the first message,
     * which is the opposite of NOTIFY-001's "would otherwise have no reason to
     * check for". A credential that clears and blocks again is a new fact and
     * notifies again.
     *
     * What the message may say about *why* is decided in the composer, because
     * NOTIFY-002 forbids disclosing Do Not Staff and one of the block reasons
     * is exactly that.
     */
    private function notifyBlocked(EventCredential $credential, ?User $changedBy): void
    {
        $credential->loadMissing(['event.organization', 'staff']);
        $staff = $credential->staff;

        if (! $staff instanceof Staff) {
            return;
        }

        $this->notifications->dispatch(
            type: NotificationType::CredentialEligibilityBlocked,
            subject: $credential,
            recipient: $this->notificationRecipients->forStaff($staff),
            organization: $credential->event?->organization,
            event: $credential->event,
            actor: $changedBy,
        );
    }
}
