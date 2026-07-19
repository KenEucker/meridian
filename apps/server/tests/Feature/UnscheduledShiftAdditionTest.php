<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\UnscheduledShiftAdditionException;
use App\Services\Shift\UnscheduledShiftAdditionService;
use App\Services\Status\StaffStatusService;
use App\Services\Training\TrainingService;
use App\Services\Waiver\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UnscheduledShiftAdditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_lead_can_add_eligible_unscheduled_staff_after_shift_start(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $moment = Carbon::parse('2026-07-01 10:00:00');

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: $moment,
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame($shiftLead->id, $outcome->assignment->assigned_by_user_id);
        $this->assertTrue($outcome->assignment->created_at->equalTo($moment));
        $this->assertFalse(
            app(CredentialEligibilityService::class)
                ->countsTowardCredentialEligibility($outcome->assignment),
        );

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.unscheduled_added')
            ->where('entity_id', $outcome->assignment->id)
            ->firstOrFail();

        $this->assertSame($shiftLead->id, $audit->actor_user_id);
        $this->assertSame($shift->event->organization_id, $audit->organization_id);
        $this->assertSame($shift->event_id, $audit->event_id);
        $this->assertSame($shift->department_id, $audit->department_id);
        $this->assertTrue($audit->after_json['unscheduled']);
    }

    public function test_department_lead_can_add_unscheduled_staff_in_their_department(): void
    {
        [$shift, $staff] = $this->unscheduledScenario();
        $departmentLead = $this->departmentLeadUserFor($shift->department);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $departmentLead,
            moment: Carbon::parse('2026-07-01 11:00:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame($departmentLead->id, $outcome->assignment->assigned_by_user_id);
    }

    public function test_unscheduled_addition_reuses_training_and_waiver_requirements(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $organization = $shift->event->organization;
        $training = Training::factory()->for($organization)->create();
        $waiver = Waiver::factory()->for($organization)->create([
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
        ]);
        app(ShiftRequirementService::class)->addTrainingRequirement($shift, $training);
        app(ShiftRequirementService::class)->addWaiverRequirement($shift, $waiver);

        try {
            app(UnscheduledShiftAdditionService::class)->addStaffToShift(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                moment: Carbon::parse('2026-07-01 10:00:00'),
            );

            $this->fail('Missing requirements should block unscheduled addition.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame(
                'Required training must be complete before unscheduled shift addition.',
                $exception->getMessage(),
            );
        }

        app(TrainingService::class)->recordCompletion($training, $staff);

        try {
            app(UnscheduledShiftAdditionService::class)->addStaffToShift(
                shift: $shift->refresh(),
                staff: $staff,
                actor: $shiftLead,
                moment: Carbon::parse('2026-07-01 10:00:00'),
            );

            $this->fail('Missing waiver should block unscheduled addition.');
        } catch (UnscheduledShiftAdditionException $exception) {
            $this->assertSame(
                'Required waiver must be complete before unscheduled shift addition.',
                $exception->getMessage(),
            );
        }

        app(WaiverService::class)->recordCompletion($waiver, $staff);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift->refresh(),
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_unscheduled_addition_blocks_ineligible_department_status(): void
    {
        [$shift, $staff, $shiftLead, $departmentMembership] = $this->unscheduledScenario();

        app(StaffStatusService::class)->transitionDepartmentStatus(
            $departmentMembership,
            DepartmentMembership::STATUS_INELIGIBLE,
            'Not eligible for this department.',
        );

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Ineligible department status prevents unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_blocks_staff_outside_eligible_team(): void
    {
        [$shift, $staff, $shiftLead, $departmentMembership] = $this->unscheduledScenario();

        TeamMembership::query()
            ->where('department_membership_id', $departmentMembership->id)
            ->update(['archived_at' => now()]);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Staff must belong to the shift eligible team before unscheduled shift addition.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_blocks_do_not_staff_organization_status(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        StaffOrganizationStatus::factory()->create([
            'organization_id' => $shift->event->organization_id,
            'staff_id' => $staff->id,
            'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
        ]);

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Do Not Staff records cannot be added to shifts.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_rejects_before_shift_start(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('Unscheduled staff can only be added after shift operations have started.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 07:59:00'),
        );
    }

    public function test_unauthorized_user_cannot_add_unscheduled_staff(): void
    {
        [$shift, $staff] = $this->unscheduledScenario();

        $this->expectException(UnscheduledShiftAdditionException::class);
        $this->expectExceptionMessage('You are not authorized to add unscheduled staff to this shift.');

        app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: User::factory()->create(),
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );
    }

    public function test_unscheduled_addition_warns_on_overlapping_assignment(): void
    {
        [$shift, $staff, $shiftLead] = $this->unscheduledScenario();
        $overlappingShift = Shift::factory()->create([
            'event_id' => $shift->event_id,
            'department_id' => $shift->department_id,
            'eligible_team_id' => $shift->eligible_team_id,
            'title' => 'Overlapping Dirt Shift',
            'starts_at' => Carbon::parse('2026-07-01 09:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 13:00:00'),
        ]);
        ShiftAssignment::factory()->create([
            'shift_id' => $overlappingShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $outcome = app(UnscheduledShiftAdditionService::class)->addStaffToShift(
            shift: $shift,
            staff: $staff,
            actor: $shiftLead,
            moment: Carbon::parse('2026-07-01 10:00:00'),
        );

        $this->assertTrue($outcome->hasOverlapWarnings());
        $this->assertSame((string) $overlappingShift->id, (string) $outcome->warnings[0]->overlappingShift->id);
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: User, 3: DepartmentMembership}
     */
    private function unscheduledScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Ranger Dirt Day Shift',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $actor = $this->shiftLeadUserFor($department->defaultTeam);
        EventDepartmentPresence::factory()->onSite()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'staff_id' => $staff->id,
            'last_marked_by_user_id' => $actor->id,
        ]);

        return [$shift, $staff, $actor, $departmentMembership];
    }

    private function shiftLeadUserFor(Team $team): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $departmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('department_logistics')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
