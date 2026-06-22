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
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Event credential eligibility calculation (CRED-001 through CRED-010; WAIVER-006).
 *
 * Manual revocation and shift removal on revoke are delivered by M7.10.
 */
class CredentialEligibilityService
{
    public const REASON_NO_SIGNED_UP_SHIFTS = 'no_signed_up_shifts';

    public const REASON_ORGANIZATION_BLOCKING_STATUS = 'organization_blocking_status';

    public const REASON_DEPARTMENT_INELIGIBLE = 'department_ineligible';

    public const REASON_MISSING_REQUIRED_WAIVER = 'missing_required_waiver';

    public const REASON_AGE_REQUIREMENT_NOT_SATISFIED = 'age_requirement_not_satisfied';

    public const REASON_MISSING_DATE_OF_BIRTH = 'missing_date_of_birth';

    public function __construct(private readonly StaffStatusService $staffStatus) {}

    /**
     * Evaluate whether the staff member satisfies credential requirements for the event.
     */
    public function evaluate(Event $event, Staff $staff, ?Carbon $asOf = null): CredentialEvaluation
    {
        $asOf ??= Carbon::now();
        $event->loadMissing('organization');

        $activeAssignments = $this->activeAssignmentsForEvent($event, $staff);

        if ($activeAssignments->isEmpty()) {
            return CredentialEvaluation::blocked(self::REASON_NO_SIGNED_UP_SHIFTS);
        }

        $organizationStatus = StaffOrganizationStatus::query()
            ->where('organization_id', $event->organization_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
            return CredentialEvaluation::blocked(self::REASON_ORGANIZATION_BLOCKING_STATUS);
        }

        $workedDepartmentIds = $activeAssignments
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

        $requiredWaivers = $this->requiredWaiversForAssignments($activeAssignments);

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

        $activeAssignments = $this->activeAssignmentsForEvent($event, $staff);

        if ($activeAssignments->isEmpty()) {
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

        return $credential->refresh();
    }
}
