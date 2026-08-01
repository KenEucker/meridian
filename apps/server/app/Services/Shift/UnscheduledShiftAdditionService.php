<?php

namespace App\Services\Shift;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\EventDepartmentPresence;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInAccess;
use App\Services\Audit\AuditService;
use App\Services\Credential\CredentialEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adds eligible unscheduled staff to an in-progress shift (SLB-008, SHIFT-016).
 */
class UnscheduledShiftAdditionService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
        private readonly ShiftEligibilityService $eligibility,
        private readonly ShiftOverlapService $overlaps,
        private readonly CredentialEligibilityService $credentials,
    ) {}

    /**
     * @throws UnscheduledShiftAdditionException
     */
    public function addStaffToShift(
        Shift $shift,
        Staff $staff,
        User $actor,
        ?Carbon $moment = null,
    ): ShiftAssignmentOutcome {
        $moment ??= Carbon::now();

        return DB::transaction(function () use ($shift, $staff, $actor, $moment): ShiftAssignmentOutcome {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canCheckInForShift($actor, $shift)) {
                throw UnscheduledShiftAdditionException::unauthorized();
            }

            if ($shift->isCancelled()) {
                throw UnscheduledShiftAdditionException::cancelledShift();
            }

            if ($moment->lt($shift->starts_at)) {
                throw UnscheduledShiftAdditionException::shiftNotStarted();
            }

            $this->assertStaffEligibleForUnscheduledAddition($shift, $staff, $moment);

            $existingAssignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($existingAssignment !== null) {
                throw UnscheduledShiftAdditionException::alreadyAssigned();
            }

            $warnings = $this->overlaps->warningsFor($staff, $shift);

            $assignment = new ShiftAssignment([
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'assigned_by_user_id' => $actor->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
                'removed_at' => null,
            ]);
            $assignment->created_at = $moment;
            $assignment->updated_at = $moment;
            $assignment->save();

            $this->audit->recordForEntity(
                entity: $assignment,
                action: 'shift_assignment.unscheduled_added',
                actorUser: $actor,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($assignment, $warnings),
                sourceContext: AuditEvent::SOURCE_API,
            );

            $this->credentials->recalculate($shift->event, $staff, $moment, $actor);

            return new ShiftAssignmentOutcome(
                assignment: $assignment->load(['shift', 'staff']),
                warnings: $warnings,
            );
        });
    }

    /**
     * @throws UnscheduledShiftAdditionException
     */
    private function assertStaffEligibleForUnscheduledAddition(Shift $shift, Staff $staff, Carbon $moment): void
    {
        $organizationId = $shift->event?->organization_id;

        if ($organizationId !== null) {
            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organizationId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                throw UnscheduledShiftAdditionException::doNotStaff();
            }
        }

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($departmentMembership === null) {
            throw UnscheduledShiftAdditionException::noDepartmentMembership();
        }

        $isOnSite = EventDepartmentPresence::query()
            ->where('event_id', $shift->event_id)
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->where('current_state', EventDepartmentPresence::STATE_ON_SITE)
            ->exists();

        if (! $isOnSite) {
            throw UnscheduledShiftAdditionException::staffNotOnSite();
        }

        try {
            $this->eligibility->assertMeetsAssignmentRequirements($shift, $staff, $departmentMembership, $moment);
        } catch (ShiftSignupException $exception) {
            throw $this->unscheduledExceptionFor($exception);
        }

        // The same rule the Logistics Desk decides whether to offer the addition
        // by, asked from one place so the two cannot drift (SLB-008).
        $hasEligibleTeamMembership = TeamMembership::query()
            ->onEligibleShiftTeam($departmentMembership)
            ->where('team_id', $shift->eligible_team_id)
            ->where('staff_id', $staff->id)
            ->exists();

        if (! $hasEligibleTeamMembership) {
            throw UnscheduledShiftAdditionException::notEligibleTeamMember();
        }
    }

    private function unscheduledExceptionFor(ShiftSignupException $exception): UnscheduledShiftAdditionException
    {
        return match ($exception->getMessage()) {
            ShiftSignupException::departmentIneligible()->getMessage() => UnscheduledShiftAdditionException::departmentIneligible(),
            ShiftSignupException::missingRequiredTraining()->getMessage() => UnscheduledShiftAdditionException::missingRequiredTraining(),
            ShiftSignupException::missingRequiredWaiver()->getMessage() => UnscheduledShiftAdditionException::missingRequiredWaiver(),
            default => new UnscheduledShiftAdditionException($exception->getMessage()),
        };
    }

    /**
     * @param  list<ShiftOverlapWarning>  $warnings
     * @return array<string, mixed>
     */
    private function auditSnapshot(ShiftAssignment $assignment, array $warnings): array
    {
        return [
            'shift_id' => $assignment->shift_id,
            'staff_id' => $assignment->staff_id,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assignment_status' => $assignment->assignment_status,
            'unscheduled' => true,
            'added_at' => $assignment->created_at?->toIso8601String(),
            'overlap_warning_count' => count($warnings),
        ];
    }
}
