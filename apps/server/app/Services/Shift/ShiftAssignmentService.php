<?php

namespace App\Services\Shift;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lead-driven shift assignment with elevated overlap authority (SHIFT-015).
 */
class ShiftAssignmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ShiftAssignmentAccess $access,
        private readonly ShiftEligibilityService $eligibility,
        private readonly ShiftOverlapService $overlaps,
    ) {}

    /**
     * @throws ShiftAssignmentException when assignment is not permitted
     */
    public function assignStaffToShift(
        Shift $shift,
        Staff $staff,
        User $assigner,
        ?Carbon $moment = null,
    ): ShiftAssignmentOutcome {
        $moment ??= Carbon::now();

        if (! $this->access->canAssignToShift($assigner, $shift)) {
            throw ShiftAssignmentException::unauthorized();
        }

        return DB::transaction(function () use ($shift, $staff, $assigner, $moment): ShiftAssignmentOutcome {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertShiftAcceptsAssignment($shift, $moment);
            $this->assertStaffEligibleForAssignment($shift, $staff, $moment);

            $existingAssignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existingAssignment !== null && $existingAssignment->removed_at === null) {
                throw ShiftAssignmentException::alreadyAssigned();
            }

            $warnings = $this->overlaps->warningsFor($staff, $shift);

            $assignment = ShiftAssignment::query()->create([
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'assigned_by_user_id' => $assigner->id,
                'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            ]);

            $this->audit->recordForEntity(
                entity: $assignment,
                action: 'shift_assignment.assigned',
                actorUser: $assigner,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($assignment),
                sourceContext: AuditEvent::SOURCE_API,
            );

            return new ShiftAssignmentOutcome(
                assignment: $assignment->load(['shift', 'staff']),
                warnings: $warnings,
            );
        });
    }

    /**
     * @throws ShiftAssignmentException
     */
    private function assertShiftAcceptsAssignment(Shift $shift, Carbon $moment): void
    {
        if ($shift->isCancelled()) {
            throw ShiftAssignmentException::cancelledShift();
        }

        if (! $shift->isSignupOpenAt($moment)) {
            throw ShiftAssignmentException::signupClosed();
        }
    }

    /**
     * @throws ShiftAssignmentException
     */
    private function assertStaffEligibleForAssignment(Shift $shift, Staff $staff, Carbon $moment): void
    {
        $organizationId = $shift->event?->organization_id;

        if ($organizationId !== null) {
            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organizationId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                throw ShiftAssignmentException::doNotStaff();
            }
        }

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($departmentMembership === null) {
            throw ShiftAssignmentException::noDepartmentMembership();
        }

        $this->eligibility->assertMeetsAssignmentRequirements($shift, $staff, $departmentMembership, $moment);

        $hasEligibleTeamMembership = TeamMembership::query()
            ->active()
            ->where('team_id', $shift->eligible_team_id)
            ->where('staff_id', $staff->id)
            ->where('department_membership_id', $departmentMembership->id)
            ->exists();

        if (! $hasEligibleTeamMembership) {
            throw ShiftAssignmentException::notEligibleTeamMember();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(ShiftAssignment $assignment): array
    {
        return [
            'shift_id' => $assignment->shift_id,
            'staff_id' => $assignment->staff_id,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assignment_status' => $assignment->assignment_status,
        ];
    }
}
