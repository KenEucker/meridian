<?php

namespace Tests\Feature;

use App\Models\CreditPolicy;
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
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Product-path shift administration for department and team leads (M11.17;
 * SHIFT-001 through SHIFT-010; UI contract 12.4).
 */
class ShiftAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_lead_creates_updates_and_cancels_a_shift(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        $training = Training::factory()->create([
            'organization_id' => $department->organization_id,
            'department_id' => $department->id,
        ]);
        $waiver = Waiver::factory()->create([
            'organization_id' => $department->organization_id,
        ]);

        $startsAt = Carbon::now()->addWeek()->setTime(8, 0);

        $create = $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'eligible_team_id' => $team->id,
                'title' => '  Gate Watch  ',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
                'capacity' => 4,
                'signup_opens_at' => Carbon::now()->addDay()->toIso8601String(),
                'signup_closes_at' => Carbon::now()->addDays(5)->toIso8601String(),
                'schedule_lock_at' => $startsAt->copy()->subDay()->toIso8601String(),
                'required_training_ids' => [$training->id],
                'required_waiver_ids' => [$waiver->id],
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Gate Watch')
            ->assertJsonPath('eligible_team_id', $team->id)
            ->assertJsonPath('capacity', 4)
            ->assertJsonPath('required_training_ids.0', $training->id)
            ->assertJsonPath('required_waiver_ids.0', $waiver->id);

        $shiftId = (string) $create->json('id');

        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift.created',
            'entity_id' => $shiftId,
            'actor_user_id' => $actor->id,
        ]);

        // Index lists the shift with team/requirement context.
        $this->actingAsClient($actor)
            ->getJson("/api/departments/{$department->id}/shifts")
            ->assertOk()
            ->assertJsonPath('access.can_administer', true)
            ->assertJsonFragment(['id' => $shiftId, 'title' => 'Gate Watch']);

        // Update keeps the schedule but drops the waiver requirement.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shiftId,
                'eligible_team_id' => $team->id,
                'title' => 'Gate Watch (Night)',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(8)->toIso8601String(),
                'capacity' => 6,
                'required_training_ids' => [$training->id],
                'required_waiver_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Gate Watch (Night)')
            ->assertJsonPath('capacity', 6)
            ->assertJsonPath('required_waiver_ids', []);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift.updated',
            'entity_id' => $shiftId,
        ]);
        $this->assertDatabaseMissing('shift_waiver_requirements', [
            'shift_id' => $shiftId,
            'waiver_id' => $waiver->id,
        ]);

        // Cancel and restore before start are soft transitions.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/cancel-shift', ['shift_id' => $shiftId])
            ->assertOk();
        $this->assertNotNull(Shift::query()->findOrFail($shiftId)->cancelled_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift.cancelled',
            'entity_id' => $shiftId,
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/restore-shift', ['shift_id' => $shiftId])
            ->assertOk()
            ->assertJsonPath('cancelled_at', null);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'shift.restored',
            'entity_id' => $shiftId,
        ]);
    }

    public function test_schedule_validation_rules_are_enforced(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $startsAt = Carbon::now()->addWeek();

        $base = [
            'department_id' => $department->id,
            'event_id' => $event->id,
            'eligible_team_id' => $team->id,
            'title' => 'Watch',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHours(4)->toIso8601String(),
        ];

        // End must be after start (SHIFT-002).
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                ...$base,
                'ends_at' => $startsAt->copy()->subHour()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift end must be after shift start.');

        // Signup close must be after open (SHIFT-008).
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                ...$base,
                'signup_opens_at' => Carbon::now()->addDays(3)->toIso8601String(),
                'signup_closes_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Signup close must be after signup open.');

        // Capacity must be positive when set (SHIFT-007).
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [...$base, 'capacity' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Capacity must be at least 1 when set.');

        // Eligible team must belong to the department (SHIFT-004).
        $foreignTeam = Team::factory()->create();
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                ...$base,
                'eligible_team_id' => $foreignTeam->id,
            ])
            ->assertForbidden();

        // Event must belong to the department organization (SHIFT-001).
        $foreignEvent = Event::factory()->create();
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                ...$base,
                'event_id' => $foreignEvent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shifts must belong to an event in the department organization.');
    }

    public function test_started_shifts_lock_schedule_team_and_cancellation(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $otherTeam = Team::factory()->for($department)->create(['code' => 'OPERATORS']);

        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Started Watch',
            'starts_at' => Carbon::now()->subHours(2),
            'ends_at' => Carbon::now()->addHours(4),
        ]);

        $payload = [
            'shift_id' => $shift->id,
            'eligible_team_id' => $team->id,
            'title' => 'Started Watch',
            'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at->toIso8601String(),
        ];

        // Schedule changes are locked once the shift has started.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                ...$payload,
                'ends_at' => Carbon::now()->addHours(6)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Scheduled times are locked once the shift has started.');

        // The eligible team is locked once the shift has started.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                ...$payload,
                'eligible_team_id' => $otherTeam->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The eligible team is locked once the shift has started.');

        // Non-schedule fields stay editable for permitted leads.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                ...$payload,
                'title' => 'Started Watch (Renamed)',
                'capacity' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Started Watch (Renamed)');

        // Started shifts cannot be cancelled.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/cancel-shift', ['shift_id' => $shift->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shifts cannot be cancelled once they have started.');
    }

    public function test_capacity_cannot_drop_below_active_assignments(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);

        $shift = Shift::factory()->withCapacity(4)->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);
        ShiftAssignment::factory()->count(3)->create([
            'shift_id' => $shift->id,
            'removed_at' => null,
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shift->id,
                'eligible_team_id' => $team->id,
                'title' => $shift->title,
                'starts_at' => $shift->starts_at->toIso8601String(),
                'ends_at' => $shift->ends_at->toIso8601String(),
                'capacity' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Capacity cannot be set below the current number of assigned staff.');
    }

    public function test_cancelled_shifts_must_be_restored_before_editing(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);

        $shift = Shift::factory()->cancelled()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shift->id,
                'eligible_team_id' => $team->id,
                'title' => 'Renamed',
                'starts_at' => $shift->starts_at->toIso8601String(),
                'ends_at' => $shift->ends_at->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cancelled shifts must be restored before editing.');
    }

    public function test_team_lead_manages_only_led_team_shifts(): void
    {
        [$department, , $event] = $this->departmentWithLead();
        $ledTeam = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        $peerTeam = Team::factory()->for($department)->create(['name' => 'Operators', 'code' => 'OPERATORS']);
        $teamLead = $this->teamLeadFor($ledTeam);

        $ledShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $ledTeam->id,
            'title' => 'Led Shift',
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);
        $peerShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $peerTeam->id,
            'title' => 'Peer Shift',
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);

        // Index is scoped to led-team shifts, and the eligible-team options it
        // offers are scoped the same way: the form cannot propose a team the
        // command would refuse (M16.18).
        $this->actingAsClient($teamLead)
            ->getJson("/api/departments/{$department->id}/shifts")
            ->assertOk()
            ->assertJsonPath('access.can_administer', false)
            ->assertJsonPath('access.can_manage', true)
            ->assertJsonPath('shifts.0.id', $ledShift->id)
            ->assertJsonPath('shifts.0.can_manage', true)
            ->assertJsonMissing(['id' => $peerShift->id])
            ->assertJsonPath('teams', [[
                'id' => (string) $ledTeam->id,
                'name' => 'Dirt',
                'code' => 'DIRT',
                'is_default' => false,
            ]]);

        // Team lead can create shifts for the led team.
        $startsAt = Carbon::now()->addWeeks(2);
        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/create-shift', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'eligible_team_id' => $ledTeam->id,
                'title' => 'New Led Shift',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(4)->toIso8601String(),
            ])
            ->assertCreated();

        // But not for peer teams.
        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/create-shift', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'eligible_team_id' => $peerTeam->id,
                'title' => 'Denied Shift',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(4)->toIso8601String(),
            ])
            ->assertForbidden();

        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $peerShift->id,
                'eligible_team_id' => $peerTeam->id,
                'title' => 'Denied Edit',
                'starts_at' => $peerShift->starts_at->toIso8601String(),
                'ends_at' => $peerShift->ends_at->toIso8601String(),
            ])
            ->assertForbidden();

        $this->actingAsClient($teamLead)
            ->getJson("/api/departments/{$department->id}/shifts/{$peerShift->id}")
            ->assertForbidden();

        // A led shift cannot be moved to a team the lead does not manage.
        $this->actingAsClient($teamLead)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $ledShift->id,
                'eligible_team_id' => $peerTeam->id,
                'title' => 'Led Shift',
                'starts_at' => $ledShift->starts_at->toIso8601String(),
                'ends_at' => $ledShift->ends_at->toIso8601String(),
            ])
            ->assertForbidden();
    }

    /**
     * A department member reads the shifts their teams are eligible for and
     * manages none of them (M16.18; SHIFT-004).
     *
     * This is the Staff menu's Shifts entry. It is a schedule, not an
     * administration surface: no option lists come back, every row says the
     * caller may not manage it, cancelled shifts are left out because nobody is
     * expected at them, and the commands refuse as they always have.
     */
    public function test_department_member_reads_their_team_shifts_read_only(): void
    {
        [$department, , $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        $peerTeam = Team::factory()->for($department)->create(['code' => 'OPERATORS']);

        $staff = Staff::factory()->create();
        $member = User::factory()->create();
        $member->staffProfiles()->attach($staff->id);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        $eligibleShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Eligible Shift',
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);
        $cancelledShift = Shift::factory()->cancelled()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);
        $peerShift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $peerTeam->id,
            'starts_at' => Carbon::now()->addWeek(),
            'ends_at' => Carbon::now()->addWeek()->addHours(6),
        ]);

        $this->actingAsClient($member)
            ->getJson("/api/departments/{$department->id}/shifts")
            ->assertOk()
            ->assertJsonPath('access.can_manage', false)
            ->assertJsonPath('access.can_administer', false)
            ->assertJsonPath('teams', [])
            ->assertJsonPath('training_options', [])
            ->assertJsonPath('waiver_options', [])
            ->assertJsonCount(1, 'shifts')
            ->assertJsonPath('shifts.0.id', $eligibleShift->id)
            ->assertJsonPath('shifts.0.eligible_team_name', 'Dirt')
            ->assertJsonPath('shifts.0.can_manage', false)
            ->assertJsonMissing(['id' => $cancelledShift->id])
            ->assertJsonMissing(['id' => $peerShift->id]);

        // A member's shift detail is still refused: nothing here is theirs to
        // manage, and the edit surface it feeds is not theirs to open.
        $this->actingAsClient($member)
            ->getJson("/api/departments/{$department->id}/shifts/{$eligibleShift->id}")
            ->assertForbidden();

        $startsAt = Carbon::now()->addWeek();
        $this->actingAsClient($member)
            ->postJson('/api/commands/create-shift', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'eligible_team_id' => $team->id,
                'title' => 'Denied',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(4)->toIso8601String(),
            ])
            ->assertForbidden();

        $this->actingAsClient($member)
            ->postJson('/api/commands/cancel-shift', ['shift_id' => $eligibleShift->id])
            ->assertForbidden();
    }

    public function test_staff_outside_the_department_cannot_view_or_manage_shifts(): void
    {
        [$department, , $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);

        // Staff in the organization, but with no team membership in this
        // department: no shift here is theirs to be at.
        $staff = Staff::factory()->create();
        $plainUser = User::factory()->create();
        $plainUser->staffProfiles()->attach($staff->id);
        DepartmentMembership::factory()->for($department)->for($staff)->create();

        $this->actingAsClient($plainUser)
            ->getJson("/api/departments/{$department->id}/shifts")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to view shifts for this department.');

        $startsAt = Carbon::now()->addWeek();
        $this->actingAsClient($plainUser)
            ->postJson('/api/commands/create-shift', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'eligible_team_id' => $team->id,
                'title' => 'Denied',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(4)->toIso8601String(),
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Department, 1: User, 2: Event}
     */
    public function test_a_shift_names_and_clears_its_own_credit_policy(): void
    {
        // SHIFT-010 / M18.16: the shift override the resolver prefers over the
        // organization default (CREDIT-002).
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $policy = CreditPolicy::factory()->create([
            'organization_id' => $department->organization_id,
            'name' => 'Overnight Gate',
            'credit_multiplier' => '2.000',
        ]);

        $startsAt = Carbon::now()->addWeek()->setTime(8, 0);

        $shiftId = (string) $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                'department_id' => $department->id,
                'event_id' => $event->id,
                'eligible_team_id' => $team->id,
                'title' => 'Gate Watch',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
                'credit_policy_id' => $policy->id,
            ])
            ->assertCreated()
            ->assertJsonPath('credit_policy_id', $policy->id)
            ->json('id');

        // The edit read offers the organization's active policies.
        $optionIds = array_column(
            $this->actingAsClient($actor)
                ->getJson("/api/departments/{$department->id}/shifts/{$shiftId}")
                ->assertOk()
                ->assertJsonPath('credit_policy_id', $policy->id)
                ->json('credit_policy_options'),
            'id',
        );
        $this->assertContains($policy->id, $optionIds);

        // Clearing the override sends the shift back to the organization
        // default (CREDIT-003).
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shiftId,
                'eligible_team_id' => $team->id,
                'title' => 'Gate Watch',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
                'credit_policy_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('credit_policy_id', null);

        $this->assertNull(Shift::query()->findOrFail($shiftId)->credit_policy_id);
    }

    public function test_a_foreign_or_newly_archived_credit_policy_is_refused_and_a_kept_one_is_not(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $startsAt = Carbon::now()->addWeek()->setTime(8, 0);

        $base = [
            'department_id' => $department->id,
            'event_id' => $event->id,
            'eligible_team_id' => $team->id,
            'title' => 'Gate Watch',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
        ];

        // Another organization's policy would price this organization's work
        // at somebody else's rate.
        $foreign = CreditPolicy::factory()->create(['credit_multiplier' => '1.000']);
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [...$base, 'credit_policy_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The credit policy must belong to the department organization.');

        // An archived policy cannot be newly chosen…
        $archived = CreditPolicy::factory()->create([
            'organization_id' => $department->organization_id,
            'name' => 'Retired Rate',
            'credit_multiplier' => '1.000',
            'archived_at' => Carbon::now()->subDay(),
        ]);
        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [...$base, 'credit_policy_id' => $archived->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Archived credit policies cannot be selected for a shift.');

        // …but a shift already naming one keeps it through an unrelated edit:
        // archiving withdraws a policy from future selection, it does not
        // restate what scheduled work will be priced at (CREDIT-002).
        $shift = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Night Watch',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(6),
            'credit_policy_id' => $archived->id,
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shift->id,
                'eligible_team_id' => $team->id,
                'title' => 'Night Watch (renamed)',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
                'credit_policy_id' => $archived->id,
            ])
            ->assertOk()
            ->assertJsonPath('credit_policy_id', $archived->id);

        // The edit read still renders the kept policy, flagged archived.
        $options = $this->actingAsClient($actor)
            ->getJson("/api/departments/{$department->id}/shifts/{$shift->id}")
            ->assertOk()
            ->json('credit_policy_options');
        $kept = collect($options)->firstWhere('id', $archived->id);
        $this->assertNotNull($kept);
        $this->assertTrue((bool) $kept['archived']);
    }

    public function test_a_shift_carries_a_custom_rate_as_its_own_shift_scoped_policy(): void
    {
        // M18.16: pre- and post-event work is typically priced below the
        // standard hour, so a shift may carry its own rate between 0 and 2
        // without the organization publishing a named policy for it.
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $startsAt = Carbon::now()->addWeek()->setTime(8, 0);

        $base = [
            'department_id' => $department->id,
            'event_id' => $event->id,
            'eligible_team_id' => $team->id,
            'title' => 'Early Build',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
        ];

        $shiftId = (string) $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [...$base, 'custom_credit_multiplier' => 0.5])
            ->assertCreated()
            ->assertJsonPath('custom_credit_multiplier', '0.500')
            ->json('id');

        // The rate is a real shift-scoped policy row, so the resolver, the
        // ledger, and the export read it exactly like a named policy.
        $policy = CreditPolicy::query()->where('shift_id', $shiftId)->firstOrFail();
        $this->assertSame('0.500', (string) $policy->credit_multiplier);
        $this->assertSame($policy->id, Shift::query()->findOrFail($shiftId)->credit_policy_id);

        // Re-rating updates the same row rather than minting a second one.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shiftId,
                'eligible_team_id' => $team->id,
                'title' => 'Early Build',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
                'custom_credit_multiplier' => '1.25',
            ])
            ->assertOk()
            ->assertJsonPath('custom_credit_multiplier', '1.250');

        $this->assertSame(1, CreditPolicy::query()->where('shift_id', $shiftId)->count());
        $this->assertSame('1.250', (string) $policy->refresh()->credit_multiplier);

        // Switching back to the organization default leaves the row behind
        // unpointed-at — hours may already have been credited against it.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/update-shift', [
                'shift_id' => $shiftId,
                'eligible_team_id' => $team->id,
                'title' => 'Early Build',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
                'credit_policy_id' => null,
                'custom_credit_multiplier' => null,
            ])
            ->assertOk()
            ->assertJsonPath('credit_policy_id', null)
            ->assertJsonPath('custom_credit_multiplier', null);

        $this->assertSame(1, CreditPolicy::query()->where('shift_id', $shiftId)->count());
    }

    public function test_a_custom_rate_outside_zero_to_two_or_beside_a_named_policy_is_refused(): void
    {
        [$department, $actor, $event] = $this->departmentWithLead();
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $policy = CreditPolicy::factory()->create([
            'organization_id' => $department->organization_id,
            'credit_multiplier' => '1.000',
        ]);
        $startsAt = Carbon::now()->addWeek()->setTime(8, 0);

        $base = [
            'department_id' => $department->id,
            'event_id' => $event->id,
            'eligible_team_id' => $team->id,
            'title' => 'Early Build',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHours(6)->toIso8601String(),
        ];

        foreach ([-0.5, 2.5, 0.1234] as $rate) {
            $this->actingAsClient($actor)
                ->postJson('/api/commands/create-shift', [...$base, 'custom_credit_multiplier' => $rate])
                ->assertUnprocessable();
        }

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [
                ...$base,
                'credit_policy_id' => $policy->id,
                'custom_credit_multiplier' => 1.5,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A shift takes a named credit policy or a custom rate, not both.');

        $this->assertSame(0, Shift::query()->where('title', 'Early Build')->count());

        // Another shift's custom rate is not a catalog entry a second shift
        // may point at: a re-rate of one shift must never reprice another.
        $other = Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => 'Other Shift',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
        ]);
        $otherCustom = CreditPolicy::factory()->create([
            'organization_id' => $department->organization_id,
            'shift_id' => $other->id,
            'name' => 'Custom shift rate',
            'credit_multiplier' => '0.500',
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/create-shift', [...$base, 'credit_policy_id' => $otherCustom->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Another shift\'s custom rate cannot be chosen as this shift\'s policy.');
    }

    private function departmentWithLead(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();

        $team = Team::query()->where('department_id', $department->id)->where('is_default', true)->first()
            ?? Team::factory()->for($department)->create(['is_default' => true]);

        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', 'department_lead')->firstOrFail()->id,
        ]);

        return [$department, $user, $event];
    }

    private function teamLeadFor(Team $team): User
    {
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => 'lead',
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', 'shift_lead')->firstOrFail()->id,
        ]);

        return $user;
    }
}
