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
use App\Services\Shift\ShiftRemovalException;
use App\Services\Shift\ShiftRemovalService;
use App\Services\Shift\ShiftRequirementException;
use App\Services\Shift\ShiftRequirementService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftScheduleLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_signup_is_blocked_after_schedule_lock(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario(
            lockAt: Carbon::parse('2026-07-01 12:00:00'),
        );

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('The schedule is locked and cannot be changed.');

        app(ShiftSignupService::class)->signUp(
            $shift,
            $staff,
            $user,
            Carbon::parse('2026-07-01 12:00:00'),
        );
    }

    public function test_self_signup_is_allowed_before_schedule_lock(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario(
            lockAt: Carbon::parse('2026-07-01 12:00:00'),
        );

        $outcome = app(ShiftSignupService::class)->signUp(
            $shift,
            $staff,
            $user,
            Carbon::parse('2026-07-01 11:59:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $outcome->assignment->assignment_status);
    }

    public function test_staff_may_withdraw_before_schedule_lock(): void
    {
        [$assignment, $staff, $user] = $this->assignedScenario(
            lockAt: Carbon::parse('2026-07-01 12:00:00'),
        );

        $removed = app(ShiftRemovalService::class)->withdrawFromShift(
            $assignment,
            $staff,
            $user,
            Carbon::parse('2026-07-01 11:59:00'),
        );

        $this->assertNotNull($removed->removed_at);
        $this->assertTrue($removed->removed_at->equalTo(Carbon::parse('2026-07-01 11:59:00')));

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.withdrawn')
            ->where('entity_id', $removed->id)
            ->firstOrFail();

        $this->assertSame($user->id, $audit->actor_user_id);
    }

    public function test_staff_may_not_withdraw_after_schedule_lock(): void
    {
        [$assignment, $staff, $user] = $this->assignedScenario(
            lockAt: Carbon::parse('2026-07-01 12:00:00'),
        );

        $this->expectException(ShiftRemovalException::class);
        $this->expectExceptionMessage('The schedule is locked and cannot be changed.');

        app(ShiftRemovalService::class)->withdrawFromShift(
            $assignment,
            $staff,
            $user,
            Carbon::parse('2026-07-01 12:00:00'),
        );
    }

    public function test_department_lead_may_remove_staff_after_schedule_lock(): void
    {
        [$assignment, , , $departmentLead] = $this->assignedScenario(
            lockAt: Carbon::parse('2026-07-01 12:00:00'),
            includeDepartmentLead: true,
        );

        $removed = app(ShiftRemovalService::class)->removeStaffFromShift(
            $assignment,
            $departmentLead,
            Carbon::parse('2026-07-01 13:00:00'),
        );

        $this->assertNotNull($removed->removed_at);

        $audit = AuditEvent::query()
            ->where('action', 'shift_assignment.removed')
            ->where('entity_id', $removed->id)
            ->firstOrFail();

        $this->assertSame($departmentLead->id, $audit->actor_user_id);
    }

    public function test_relative_cutoff_resolves_against_the_event_window(): void
    {
        // SHIFT-017: an offset before the active event window start resolves
        // to an absolute moment whenever the window is known.
        [$shift] = $this->eligibleSignupScenario();
        $shift->event->forceFill([
            'active_event_window_starts_at' => Carbon::parse('2026-07-08 09:00:00'),
        ])->save();

        app(ShiftRequirementService::class)->setScheduleLock(
            $shift,
            offsetMinutes: 24 * 60,
        );
        $shift->refresh()->load('event');

        $this->assertTrue($shift->hasScheduleLock());
        $this->assertTrue(
            $shift->resolvedScheduleLockAt()->equalTo(Carbon::parse('2026-07-07 09:00:00')),
        );
        $this->assertFalse($shift->isScheduleLockedAt(Carbon::parse('2026-07-07 08:59:00')));
        $this->assertTrue($shift->isScheduleLockedAt(Carbon::parse('2026-07-07 09:00:00')));
    }

    public function test_moving_the_event_window_moves_a_relative_cutoff_and_leaves_an_absolute_one_alone(): void
    {
        [$relativeShift] = $this->eligibleSignupScenario();
        $event = $relativeShift->event;
        $event->forceFill([
            'active_event_window_starts_at' => Carbon::parse('2026-07-08 09:00:00'),
        ])->save();

        $absoluteShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $relativeShift->department_id,
            'eligible_team_id' => $relativeShift->eligible_team_id,
            'title' => 'Gate Close',
            'starts_at' => Carbon::parse('2026-07-10 08:00:00'),
            'ends_at' => Carbon::parse('2026-07-10 16:00:00'),
            'schedule_lock_at' => Carbon::parse('2026-07-07 09:00:00'),
        ]);

        app(ShiftRequirementService::class)->setScheduleLock(
            $relativeShift,
            offsetMinutes: 24 * 60,
        );

        // The event slips a week.
        $event->forceFill([
            'active_event_window_starts_at' => Carbon::parse('2026-07-15 09:00:00'),
        ])->save();

        $relativeShift = $relativeShift->refresh()->load('event');
        $absoluteShift = $absoluteShift->refresh()->load('event');

        // SHIFT-017: the relative cutoff rides the window; the absolute one
        // stays where it was put.
        $this->assertTrue(
            $relativeShift->resolvedScheduleLockAt()->equalTo(Carbon::parse('2026-07-14 09:00:00')),
        );
        $this->assertFalse($relativeShift->isScheduleLockedAt(Carbon::parse('2026-07-08 09:00:00')));
        $this->assertTrue(
            $absoluteShift->resolvedScheduleLockAt()->equalTo(Carbon::parse('2026-07-07 09:00:00')),
        );
        $this->assertTrue($absoluteShift->isScheduleLockedAt(Carbon::parse('2026-07-08 09:00:00')));
    }

    public function test_relative_cutoff_without_an_event_window_never_locks(): void
    {
        // An offset from a moment nobody has named is not a moment: until the
        // active event window is set, a relative cutoff resolves to nothing
        // and self-service changes stay governed by other rules.
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $shift->event->forceFill([
            'active_event_window_starts_at' => null,
            'active_event_window_ends_at' => null,
        ])->save();

        app(ShiftRequirementService::class)->setScheduleLock(
            $shift,
            offsetMinutes: 24 * 60,
        );
        $shift->refresh()->load('event');

        $this->assertTrue($shift->hasScheduleLock());
        $this->assertNull($shift->resolvedScheduleLockAt());
        $this->assertFalse($shift->isScheduleLockedAt(Carbon::parse('2027-01-01 00:00:00')));

        $outcome = app(ShiftSignupService::class)->signUp(
            $shift,
            $staff,
            $user,
            Carbon::parse('2026-07-01 11:59:00'),
        );

        $this->assertSame(ShiftAssignment::STATUS_SIGNED_UP, $outcome->assignment->assignment_status);
    }

    public function test_relative_signup_is_blocked_after_a_resolved_relative_cutoff(): void
    {
        [$shift, $staff, $user] = $this->eligibleSignupScenario();
        $shift->event->forceFill([
            'active_event_window_starts_at' => Carbon::parse('2026-07-08 09:00:00'),
        ])->save();

        app(ShiftRequirementService::class)->setScheduleLock(
            $shift,
            offsetMinutes: 24 * 60,
        );

        $this->expectException(ShiftSignupException::class);
        $this->expectExceptionMessage('The schedule is locked and cannot be changed.');

        app(ShiftSignupService::class)->signUp(
            $shift->refresh(),
            $staff,
            $user,
            Carbon::parse('2026-07-07 09:00:00'),
        );
    }

    public function test_a_cutoff_cannot_carry_both_forms(): void
    {
        [$shift] = $this->eligibleSignupScenario();

        $this->expectException(ShiftRequirementException::class);
        $this->expectExceptionMessage('A schedule cutoff is either an absolute time or an offset before the event window, not both.');

        app(ShiftRequirementService::class)->setScheduleLock(
            $shift,
            Carbon::parse('2026-07-01 12:00:00'),
            offsetMinutes: 60,
        );
    }

    public function test_unauthorized_user_cannot_remove_staff_from_shift(): void
    {
        [$assignment] = $this->assignedScenario(
            lockAt: Carbon::parse('2026-07-01 12:00:00'),
        );
        $otherUser = User::factory()->create();

        $this->expectException(ShiftRemovalException::class);
        $this->expectExceptionMessage('You are not authorized to remove staff from this shift.');

        app(ShiftRemovalService::class)->removeStaffFromShift($assignment, $otherUser);
    }

    public function test_lead_removal_rejects_already_removed_assignment(): void
    {
        [$assignment, , , $departmentLead] = $this->assignedScenario(
            includeDepartmentLead: true,
        );
        $service = app(ShiftRemovalService::class);

        $service->removeStaffFromShift($assignment, $departmentLead);

        $this->expectException(ShiftRemovalException::class);
        $this->expectExceptionMessage('This shift assignment has already been removed.');

        $service->removeStaffFromShift($assignment->refresh(), $departmentLead);
    }

    /**
     * @return array{0: Shift, 1: Staff, 2: User}
     */
    private function eligibleSignupScenario(?Carbon $lockAt = null): array
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
            'schedule_lock_at' => $lockAt,
        ]);

        return [$shift, $staff, $user];
    }

    /**
     * @return array{0: ShiftAssignment, 1: Staff, 2: User, 3?: User}
     */
    private function assignedScenario(
        ?Carbon $lockAt = null,
        bool $includeDepartmentLead = false,
    ): array {
        [$shift, $staff, $user] = $this->eligibleSignupScenario($lockAt);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
            'removed_at' => null,
        ]);

        if (! $includeDepartmentLead) {
            return [$assignment, $staff, $user];
        }

        return [$assignment, $staff, $user, $this->departmentLeadUserFor($shift->department)];
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
