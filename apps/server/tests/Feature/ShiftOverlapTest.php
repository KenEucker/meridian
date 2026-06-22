<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Shift\ShiftAssignmentException;
use App\Services\Shift\ShiftAssignmentService;
use App\Services\Shift\ShiftOverlapService;
use App\Services\Shift\ShiftOverlapWarning;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_adjacent_shifts_do_not_overlap(): void
    {
        $first = Shift::factory()->create([
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);
        $second = Shift::factory()->create([
            'starts_at' => Carbon::parse('2026-07-01 16:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 24:00:00'),
        ]);

        $this->assertFalse($first->overlaps($second));
        $this->assertFalse($second->overlaps($first));
    }

    public function test_partially_overlapping_shifts_overlap(): void
    {
        $first = Shift::factory()->create([
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);
        $second = Shift::factory()->create([
            'starts_at' => Carbon::parse('2026-07-01 12:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 20:00:00'),
        ]);

        $this->assertTrue($first->overlaps($second));
        $this->assertTrue($second->overlaps($first));
    }

    public function test_overlap_service_ignores_cancelled_and_removed_assignments(): void
    {
        [$staff, $existingShift, $candidateShift] = $this->overlapScenario();

        $existingShift->forceFill(['cancelled_at' => now()])->save();
        $this->assertSame([], app(ShiftOverlapService::class)->warningsFor($staff, $candidateShift));

        $existingShift->forceFill(['cancelled_at' => null])->save();
        ShiftAssignment::query()
            ->where('shift_id', $existingShift->id)
            ->where('staff_id', $staff->id)
            ->update(['removed_at' => now()]);

        $this->assertSame([], app(ShiftOverlapService::class)->warningsFor($staff, $candidateShift));
    }

    public function test_self_signup_warns_on_overlap_but_still_assigns(): void
    {
        [$staff, $existingShift, $candidateShift, $user] = $this->overlapScenario(returnUser: true);

        $outcome = app(ShiftSignupService::class)->signUp($candidateShift, $staff, $user);

        $this->assertTrue($outcome->hasOverlapWarnings());
        $this->assertCount(1, $outcome->warnings);
        $this->assertInstanceOf(ShiftOverlapWarning::class, $outcome->warnings[0]);
        $this->assertSame((string) $existingShift->id, (string) $outcome->warnings[0]->overlappingShift->id);
        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $outcome->assignment->assignment_status);
        $this->assertStringContainsString($existingShift->title, $outcome->warnings[0]->message());
    }

    public function test_self_signup_has_no_overlap_warnings_without_conflict(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();

        $outcome = app(ShiftSignupService::class)->signUp($shift, $staff, $user);

        $this->assertFalse($outcome->hasOverlapWarnings());
        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $outcome->assignment->assignment_status);
    }

    public function test_department_lead_can_assign_overlapping_shift(): void
    {
        [$staff, $existingShift, $candidateShift] = $this->overlapScenario();
        $departmentLead = $this->departmentLeadUserFor($candidateShift->department);

        $outcome = app(ShiftAssignmentService::class)->assignStaffToShift(
            $candidateShift,
            $staff,
            $departmentLead,
        );

        $this->assertSame(ShiftAssignment::STATUS_ASSIGNED, $outcome->assignment->assignment_status);
        $this->assertSame($departmentLead->id, $outcome->assignment->assigned_by_user_id);
        $this->assertTrue($outcome->hasOverlapWarnings());
        $this->assertSame((string) $existingShift->id, (string) $outcome->warnings[0]->overlappingShift->id);

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.assigned')
            ->where('entity_id', $outcome->assignment->id)
            ->firstOrFail();

        $this->assertSame($departmentLead->id, $audit->actor_user_id);
    }

    public function test_unauthorized_user_cannot_assign_overlapping_shift(): void
    {
        [$staff, , $candidateShift] = $this->overlapScenario();
        $otherUser = User::factory()->create();

        $this->expectException(ShiftAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign staff to this shift.');

        app(ShiftAssignmentService::class)->assignStaffToShift($candidateShift, $staff, $otherUser);
    }

    public function test_department_lead_cannot_assign_staff_in_another_department(): void
    {
        [$staff, , $candidateShift] = $this->overlapScenario();
        $otherDepartment = Department::factory()->for($candidateShift->event->organization)->create();
        $departmentLead = $this->departmentLeadUserFor($otherDepartment);

        $this->expectException(ShiftAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign staff to this shift.');

        app(ShiftAssignmentService::class)->assignStaffToShift($candidateShift, $staff, $departmentLead);
    }

    /**
     * @return array{0: Staff, 1: Shift, 2: Shift, 3?: User}
     */
    private function overlapScenario(bool $returnUser = false): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $existingShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Morning Gate',
            'starts_at' => Carbon::parse('2026-07-01 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00'),
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $existingShift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        $candidateShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Afternoon Gate',
            'starts_at' => Carbon::parse('2026-07-01 12:00:00'),
            'ends_at' => Carbon::parse('2026-07-01 20:00:00'),
        ]);

        if ($returnUser) {
            return [$staff, $existingShift, $candidateShift, $user];
        }

        return [$staff, $existingShift, $candidateShift];
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: User}
     */
    private function eligibleSignupScenario(): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam($staff, $department);
        $department->load('defaultTeam');

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $department->defaultTeam->id,
            'title' => 'Gate Lead',
            'starts_at' => Carbon::parse('2026-07-10 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-10 16:00:00'),
        ]);

        return [$shift, $staff, $user];
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);
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
            'permission_role_id' => $this->role('department_lead')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
