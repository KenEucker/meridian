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
 * Self-signup command for planned shift coverage (SHIFT-011; requirements 3.12).
 *
 * Overlap warnings, schedule lock rules, lead assignment, and removal are delivered
 * by later M7 tasks.
 */
class ShiftSignupService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ShiftEligibilityService $eligibility,
    ) {}

    /**
     * @throws ShiftSignupException when signup is not permitted
     */
    public function signUp(Shift $shift, Staff $staff, User $user, ?Carbon $moment = null): ShiftAssignment
    {
        $moment ??= Carbon::now();

        if (! $user->staffProfiles()->whereKey($staff->getKey())->exists()) {
            throw ShiftSignupException::staffNotLinkedToUser();
        }

        return DB::transaction(function () use ($shift, $staff, $user, $moment): ShiftAssignment {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertShiftAcceptsSignup($shift, $moment);
            $this->assertStaffEligibleForSignup($shift, $staff, $moment);
            $this->eligibility->assertCapacityForSelfSignup($shift);

            $existingAssignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existingAssignment !== null && $existingAssignment->removed_at === null) {
                throw ShiftSignupException::alreadySignedUp();
            }

            $assignment = ShiftAssignment::query()->create([
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'assigned_by_user_id' => null,
                'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            ]);

            $this->audit->recordForEntity(
                entity: $assignment,
                action: 'shift_assignment.signed_up',
                actorUser: $user,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($assignment),
                sourceContext: AuditEvent::SOURCE_API,
            );

            return $assignment->load(['shift', 'staff']);
        });
    }

    /**
     * @throws ShiftSignupException
     */
    private function assertShiftAcceptsSignup(Shift $shift, Carbon $moment): void
    {
        if ($shift->isCancelled()) {
            throw ShiftSignupException::cancelledShift();
        }

        if (! $shift->isSignupOpenAt($moment)) {
            throw ShiftSignupException::signupClosed();
        }
    }

    /**
     * @throws ShiftSignupException
     */
    private function assertStaffEligibleForSignup(Shift $shift, Staff $staff, Carbon $moment): void
    {
        $organizationId = $shift->event?->organization_id;

        if ($organizationId !== null) {
            $organizationStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organizationId)
                ->where('staff_id', $staff->id)
                ->first();

            if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                throw ShiftSignupException::doNotStaff();
            }
        }

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($departmentMembership === null) {
            throw ShiftSignupException::noDepartmentMembership();
        }

        $this->eligibility->assertMeetsAssignmentRequirements($shift, $staff, $departmentMembership, $moment);

        $hasEligibleTeamMembership = TeamMembership::query()
            ->active()
            ->where('team_id', $shift->eligible_team_id)
            ->where('staff_id', $staff->id)
            ->where('department_membership_id', $departmentMembership->id)
            ->exists();

        if (! $hasEligibleTeamMembership) {
            throw ShiftSignupException::notEligibleTeamMember();
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
