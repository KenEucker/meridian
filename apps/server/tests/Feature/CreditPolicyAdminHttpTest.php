<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Node;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Organization credit policy administration and calculation runs (M18.16;
 * ORG-009, ORG-020, ORG-021; CREDIT-001 through CREDIT-003).
 */
class CreditPolicyAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_lists_their_organizations_policies_with_archived_ones(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        $active = $this->policy($organization, 'Standard Credit', '1.500');
        $archived = $this->policy($organization, 'Retired Rate', '1.000', archived: true);
        $this->policy($other, 'Other organization policy', '2.000');

        $organization->forceFill(['default_credit_policy_id' => $active->id])->save();

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/credit-policies")
            ->assertOk()
            ->assertJsonCount(2, 'credit_policies')
            ->assertJsonPath('credit_policies.0.id', $archived->id)
            // Archived rows are part of the read: restoring one needs it to
            // be visible.
            ->assertJsonPath('credit_policies.0.archived', true)
            ->assertJsonPath('credit_policies.1.id', $active->id)
            ->assertJsonPath('credit_policies.1.archived', false)
            ->assertJsonPath('credit_policies.1.credit_multiplier', '1.500')
            ->assertJsonPath('credit_policies.1.is_default', true)
            ->assertJsonPath('governance.editable', true)
            ->assertJsonMissing(['name' => 'Other organization policy']);
    }

    public function test_the_read_counts_the_shifts_that_name_a_policy(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $policy = $this->policy($organization, 'Overnight Gate', '2.000');

        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();

        foreach (range(1, 2) as $index) {
            $this->shift($event, $department, sprintf('Watch %d', $index), $policy);
        }

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/credit-policies")
            ->assertOk()
            ->assertJsonPath('credit_policies.0.shift_count', 2);
    }

    public function test_an_organizer_creates_updates_archives_and_restores_a_policy(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        $created = $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-credit-policy', [
                'organization_id' => $organization->id,
                'name' => '  Standard Credit  ',
                'credit_multiplier' => 1.5,
            ])
            ->assertCreated()
            ->json('id');

        $policy = CreditPolicy::query()->findOrFail($created);
        $this->assertSame('Standard Credit', $policy->name);
        $this->assertSame('1.500', (string) $policy->credit_multiplier);

        // Rename and re-rate travel together as one update; frozen ledger
        // entries are untouched because they carry their own basis.
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-credit-policy', [
                'credit_policy_id' => $created,
                'name' => 'Standard Credit 2027',
                'credit_multiplier' => '1.75',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Standard Credit 2027')
            ->assertJsonPath('credit_multiplier', '1.750');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-credit-policy', ['credit_policy_id' => $created])
            ->assertOk()
            ->assertJsonPath('archived', true);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/restore-credit-policy', ['credit_policy_id' => $created])
            ->assertOk()
            ->assertJsonPath('archived', false);

        foreach ([
            'credit_policy.created',
            'credit_policy.updated',
            'credit_policy.archived',
            'credit_policy.restored',
        ] as $action) {
            $this->assertTrue(
                AuditEvent::query()
                    ->where('action', $action)
                    ->where('entity_id', $created)
                    ->exists(),
                sprintf('Expected an audit row for %s.', $action),
            );
        }
    }

    public function test_a_duplicate_name_is_refused_whatever_its_casing(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $this->policy($organization, 'Standard Credit', '1.500');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-credit-policy', [
                'organization_id' => $organization->id,
                'name' => 'standard credit',
                'credit_multiplier' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization already has a credit policy named standard credit.');

        // Including against an archived row, which still holds the name.
        $this->policy($organization, 'Retired Rate', '1.000', archived: true);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-credit-policy', [
                'organization_id' => $organization->id,
                'name' => 'RETIRED RATE',
                'credit_multiplier' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_an_out_of_range_or_over_precise_multiplier_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        foreach ([-1, 1001, 1.2345] as $multiplier) {
            $this->actingAsClient($organizer)
                ->postJson('/api/commands/create-credit-policy', [
                    'organization_id' => $organization->id,
                    'name' => 'Standard Credit',
                    'credit_multiplier' => $multiplier,
                ])
                ->assertStatus(422);
        }

        $this->assertSame(0, CreditPolicy::query()->count());

        // Below one is the ordinary pre/post-event shape, and zero is a
        // deliberate price rather than an absence — both are accepted.
        foreach ([['Half Rate', 0.5], ['Zero Rate', 0]] as [$name, $multiplier]) {
            $this->actingAsClient($organizer)
                ->postJson('/api/commands/create-credit-policy', [
                    'organization_id' => $organization->id,
                    'name' => $name,
                    'credit_multiplier' => $multiplier,
                ])
                ->assertCreated();
        }
    }

    public function test_the_organization_default_cannot_be_archived_while_it_holds_that_job(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $policy = $this->policy($organization, 'Standard Credit', '1.500');
        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();

        // Archiving the default would quietly stop crediting every shift that
        // names no policy of its own (CREDIT-003).
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-credit-policy', ['credit_policy_id' => $policy->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This policy is the organization default. Choose another default, or clear it, before archiving this one.',
            );

        $this->assertNull($policy->refresh()->archived_at);

        // With the default moved, the archive goes through.
        $organization->forceFill(['default_credit_policy_id' => null])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-credit-policy', ['credit_policy_id' => $policy->id])
            ->assertOk();
    }

    public function test_policy_edits_are_frozen_during_the_active_event_window(): void
    {
        // ORG-021: credit policies answer to the same governance freeze
        // configuration does — a rate is a rule of the event being played.
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $policy = $this->policy($organization, 'Standard Credit', '1.500');

        Event::factory()->for($organization)->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-credit-policy', [
                'organization_id' => $organization->id,
                'name' => 'Mid-event Rate',
                'credit_multiplier' => 3,
            ])
            ->assertStatus(409);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-credit-policy', [
                'credit_policy_id' => $policy->id,
                'name' => 'Standard Credit',
                'credit_multiplier' => 9,
            ])
            ->assertStatus(409);

        $this->assertSame('1.500', (string) $policy->refresh()->credit_multiplier);

        // The read stays open and says why edits are not.
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/credit-policies")
            ->assertOk()
            ->assertJsonPath('governance.editable', false)
            ->assertJsonPath('governance.frozen_by_event.name', fn (?string $name): bool => $name !== null);
    }

    public function test_policy_edits_are_refused_on_an_onsite_node(): void
    {
        // ORG-021: central is authoritative for credit policies.
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-credit-policy', [
                'organization_id' => $organization->id,
                'name' => 'Standard Credit',
                'credit_multiplier' => 1.5,
            ])
            ->assertStatus(409);

        $this->assertSame(0, CreditPolicy::query()->count());
    }

    public function test_a_staff_coordinator_and_an_outside_organizer_are_refused(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $policy = $this->policy($organization, 'Standard Credit', '1.500');

        $outsider = $this->organizerFor($other);
        $coordinator = $this->userWithRole('staff_coordinator', $organization);

        foreach ([$outsider, $coordinator] as $user) {
            $this->actingAsClient($user)
                ->getJson("/api/organizations/{$organization->id}/credit-policies")
                ->assertForbidden();

            $this->actingAsClient($user)
                ->postJson('/api/commands/update-credit-policy', [
                    'credit_policy_id' => $policy->id,
                    'name' => 'Repriced',
                    'credit_multiplier' => 9,
                ])
                ->assertForbidden();
        }

        $this->assertSame('Standard Credit', $policy->refresh()->name);
    }

    public function test_an_organizer_calculates_credits_for_a_settled_event(): void
    {
        Carbon::setTestNow('2026-07-15 17:00:00 UTC');

        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $policy = $this->policy($organization, 'Standard Credit', '1.500');
        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();

        $department = Department::factory()->for($organization)->create();
        // Ends 1 Jul; the 14-day ORG-017 default closed 15 Jul 16:00.
        $event = Event::factory()->for($organization)->create([
            'starts_at' => Carbon::parse('2026-06-28 09:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
        ]);
        $shift = $this->shift($event, $department, 'Gate Watch');
        $this->hours($shift, minutes: 240, frozenAt: '2026-07-14 12:00:00');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/calculate-event-credits', ['event_id' => $event->id])
            ->assertOk()
            ->assertJsonPath('entries_created', 1)
            ->assertJsonPath('hours_without_credit_policy', 0)
            ->assertJsonPath('total_credits', '6.00');

        $entry = CreditLedgerEntry::query()->firstOrFail();
        $this->assertSame($policy->id, $entry->credit_policy_id);
        $this->assertSame($organizer->id, $entry->created_by_user_id);

        $audit = AuditEvent::query()->where('action', 'event_credits.calculated')->firstOrFail();
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);

        // The events list reflects the run.
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/credit-policies")
            ->assertOk()
            ->assertJsonPath('events.0.id', $event->id)
            ->assertJsonPath('events.0.grace_closed', true)
            ->assertJsonPath('events.0.can_calculate', true)
            ->assertJsonPath('events.0.credited_hours_count', 1)
            ->assertJsonPath('events.0.uncredited_hours_count', 0);

        Carbon::setTestNow();
    }

    public function test_a_run_is_refused_in_the_services_words_while_the_grace_period_is_open(): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00 UTC');

        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $policy = $this->policy($organization, 'Standard Credit', '1.500');
        $organization->forceFill(['default_credit_policy_id' => $policy->id])->save();

        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create([
            'starts_at' => Carbon::parse('2026-06-28 09:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
        ]);
        $shift = $this->shift($event, $department, 'Gate Watch');
        $this->hours($shift, minutes: 240, frozenAt: '2026-07-05 12:00:00');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/calculate-event-credits', ['event_id' => $event->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                fn (string $message): bool => str_contains($message, 'grace period closes'),
            );

        $this->assertSame(0, CreditLedgerEntry::query()->count());

        // The events list says the same thing before the button is pressed.
        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/credit-policies")
            ->assertOk()
            ->assertJsonPath('events.0.grace_closed', false)
            ->assertJsonPath('events.0.can_calculate', false);

        Carbon::setTestNow();
    }

    public function test_a_run_is_refused_on_an_onsite_node(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $event = Event::factory()->for($organization)->create([
            'starts_at' => Carbon::parse('2026-06-28 09:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
        ]);

        Node::factory()->create([
            'node_role' => Node::ROLE_ONSITE,
            'is_local' => true,
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/calculate-event-credits', ['event_id' => $event->id])
            ->assertStatus(409);

        $this->assertSame(0, CreditLedgerEntry::query()->count());
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/organizations/{$organization->id}/credit-policies")->assertUnauthorized();
        $this->postJson('/api/commands/create-credit-policy', [
            'organization_id' => $organization->id,
            'name' => 'Standard Credit',
            'credit_multiplier' => 1.5,
        ])->assertUnauthorized();
    }

    private function policy(
        Organization $organization,
        string $name,
        string $multiplier,
        bool $archived = false,
    ): CreditPolicy {
        return CreditPolicy::factory()
            ->for($organization)
            ->multiplier($multiplier)
            ->create([
                'name' => $name,
                'archived_at' => $archived ? Carbon::parse('2026-06-01T00:00:00Z') : null,
            ]);
    }

    private function shift(
        Event $event,
        Department $department,
        string $title,
        ?CreditPolicy $policy = null,
    ): Shift {
        $team = Team::query()->where('department_id', $department->id)->where('is_default', true)->first()
            ?? Team::factory()->for($department)->create(['is_default' => true]);

        return Shift::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
            'eligible_team_id' => $team->id,
            'title' => $title,
            'starts_at' => Carbon::parse('2026-07-01 08:00:00 UTC'),
            'ends_at' => Carbon::parse('2026-07-01 16:00:00 UTC'),
            'capacity' => null,
            'credit_policy_id' => $policy?->id,
        ]);
    }

    private function hours(Shift $shift, int $minutes, ?string $frozenAt): HoursWorked
    {
        $staff = Staff::factory()->create();
        $startedAt = $shift->starts_at->copy();
        $endedAt = $startedAt->copy()->addMinutes($minutes);

        $assignment = ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'assigned_by_user_id' => null,
        ]);

        $record = AttendanceRecord::factory()->create([
            'shift_id' => $shift->id,
            'shift_assignment_id' => $assignment->id,
            'staff_id' => $staff->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
            'checked_in_at' => $startedAt,
            'checked_out_at' => $endedAt,
        ]);

        return HoursWorked::factory()->create([
            'shift_id' => $shift->id,
            'staff_id' => $staff->id,
            'attendance_record_id' => $record->id,
            'actual_started_at' => $startedAt,
            'actual_ended_at' => $endedAt,
            'minutes_worked' => $minutes,
            'frozen_at' => $frozenAt === null ? null : Carbon::parse($frozenAt, 'UTC'),
        ]);
    }

    private function organizerFor(Organization $organization): User
    {
        return $this->userWithRole('organizer', $organization);
    }

    private function userWithRole(string $roleCode, Organization $organization): User
    {
        $department = Department::factory()->for($organization)->create();

        if ($roleCode === 'organizer') {
            $organization->forceFill(['organizers_department_id' => $department->id])->save();
        }

        $team = Team::factory()->for($department)->create();
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
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
