<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Training;
use App\Models\Waiver;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftEligibilityService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Status\StaffStatusService;
use App\Services\Training\TrainingService;
use App\Services\Waiver\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_training_for_shift_assignment(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();
        $training = Training::factory()->for($shift->event->organization)->create();
        app(ShiftRequirementService::class)->addTrainingRequirement($shift, $training);

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Required training must be complete before shift signup.');

        app(ShiftEligibilityService::class)->assertMeetsAssignmentRequirements(
            $shift->refresh(),
            $staff,
            $departmentMembership,
        );
    }

    public function test_requires_waiver_for_shift_assignment(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();
        $waiver = Waiver::factory()->for($shift->event->organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $shift->event->organization_id,
        ]);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Required waiver must be complete before shift signup.');

        app(ShiftEligibilityService::class)->assertMeetsAssignmentRequirements(
            $shift->refresh(),
            $staff,
            $departmentMembership,
        );
    }

    public function test_blocks_department_ineligible_status(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();

        app(StaffStatusService::class)->transitionDepartmentStatus(
            $departmentMembership,
            DepartmentMembership::STATUS_INELIGIBLE,
            'Not eligible for this department.',
        );

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Ineligible department status prevents shift signup.');

        app(ShiftEligibilityService::class)->assertMeetsAssignmentRequirements(
            $shift,
            $staff,
            $departmentMembership->refresh(),
        );
    }

    public function test_blocks_self_signup_when_shift_is_full(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();
        $otherStaff = Staff::factory()->create();
        $shift->forceFill(['capacity' => 1])->save();

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $otherStaff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        app(ShiftEligibilityService::class)->assertMeetsAssignmentRequirements(
            $shift->refresh(),
            $staff,
            $departmentMembership,
        );

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('This shift is full and cannot accept additional signup.');

        app(ShiftEligibilityService::class)->assertCapacityForSelfSignup($shift->refresh());
    }

    public function test_allows_assignment_when_requirements_are_met_and_capacity_is_available(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();
        $organization = $shift->event->organization;
        $training = Training::factory()->for($organization)->create();
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        $requirementService = app(ShiftRequirementService::class);
        $requirementService->addTrainingRequirement($shift, $training);
        $requirementService->addWaiverRequirement($shift, $waiver);
        app(TrainingService::class)->recordCompletion($training, $staff);
        app(WaiverService::class)->recordCompletion($waiver, $staff);
        $shift->forceFill(['capacity' => 2])->save();

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => Staff::factory()->create()->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $service = app(ShiftEligibilityService::class);
        $service->assertMeetsAssignmentRequirements($shift->refresh(), $staff, $departmentMembership);
        $service->assertCapacityForSelfSignup($shift->refresh());

        $this->addToAssertionCount(1);
    }

    public function test_expired_training_completion_blocks_assignment(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();
        $training = Training::factory()->for($shift->event->organization)->create([
            'expires_after_days' => 30,
        ]);
        app(ShiftRequirementService::class)->addTrainingRequirement($shift, $training);
        app(TrainingService::class)->recordCompletion(
            $training,
            $staff,
            now()->subDays(60),
        );

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('Required training must be complete before shift signup.');

        app(ShiftEligibilityService::class)->assertMeetsAssignmentRequirements(
            $shift->refresh(),
            $staff,
            $departmentMembership,
        );
    }

    public function test_removed_assignments_do_not_count_toward_capacity(): void
    {
        [$shift, $staff, $departmentMembership] = $this->assignmentScenario();
        $shift->forceFill(['capacity' => 1])->save();

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => Staff::factory()->create()->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => now(),
        ]);

        app(ShiftEligibilityService::class)->assertMeetsAssignmentRequirements(
            $shift->refresh(),
            $staff,
            $departmentMembership,
        );
        app(ShiftEligibilityService::class)->assertCapacityForSelfSignup($shift->refresh());

        $this->addToAssertionCount(1);
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: DepartmentMembership}
     */
    private function assignmentScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Lead',
        ]);

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        return [$shift, $staff, $departmentMembership];
    }
}
