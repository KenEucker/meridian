<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event administration as a product surface (M18.29; UI contract 12.6
 * `organizer.events`; ORG-005, ORG-006; requirements 2.4).
 */
class EventAdministrationHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_reads_their_own_organizations_events(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $mine = Event::factory()->for($organization)->create(['name' => 'Emberfall 2026']);

        $other = Organization::factory()->create();
        Event::factory()->for($other)->create(['name' => 'Somebody Else\'s Event']);

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/events");

        $response->assertOk();

        $names = collect($response->json('events'))->pluck('name')->all();

        $this->assertSame(['Emberfall 2026'], $names);
        $this->assertSame((string) $mine->id, $response->json('events.0.id'));
    }

    public function test_a_caller_without_the_capability_is_refused(): void
    {
        [$organization] = $this->organizerScaffold();

        $this->actingAsClient(User::factory()->create())
            ->getJson("/api/organizations/{$organization->id}/events")
            ->assertForbidden();
    }

    /**
     * TEAM-014: the Staff Coordinator decides applications and carries no other
     * organizer governance capability, and declaring when an event runs is
     * governance.
     */
    public function test_a_staff_coordinator_does_not_reach_event_administration(): void
    {
        [$organization] = $this->organizerScaffold();
        $coordinator = $this->userHoldingRoleIn(
            $organization,
            PermissionCatalog::ROLE_STAFF_COORDINATOR,
        );

        $this->actingAsClient($coordinator)
            ->getJson("/api/organizations/{$organization->id}/events")
            ->assertForbidden();
    }

    public function test_an_organizer_of_another_organization_is_refused(): void
    {
        [, $organizer] = $this->organizerScaffold();
        $other = Organization::factory()->create();

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$other->id}/events")
            ->assertForbidden();
    }

    public function test_an_organizer_creates_an_event_and_the_creation_is_audited(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-event', [
                'organization_id' => (string) $organization->id,
                'name' => 'Emberfall 2027',
                'slug' => 'emberfall-2027',
                'timezone' => 'America/Los_Angeles',
                'starts_at' => '2027-09-01T00:00:00Z',
                'ends_at' => '2027-09-08T00:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('events.0.name', 'Emberfall 2027')
            ->assertJsonPath('events.0.slug', 'emberfall-2027');

        $event = Event::query()->where('slug', 'emberfall-2027')->firstOrFail();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'event.created',
            'entity_id' => (string) $event->id,
            'actor_user_id' => $organizer->id,
            'organization_id' => (string) $organization->id,
        ]);
    }

    public function test_an_edit_records_the_values_before_and_after_it(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'UTC',
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => 'Emberfall 2026 (Renamed)',
                'slug' => 'emberfall-2026',
                'timezone' => 'UTC',
            ])
            ->assertOk()
            ->assertJsonPath('events.0.name', 'Emberfall 2026 (Renamed)');

        $entry = AuditEvent::query()
            ->where('action', 'event.updated')
            ->where('entity_id', (string) $event->id)
            ->firstOrFail();

        $this->assertSame('Emberfall 2026', $entry->before_json['name']);
        $this->assertSame('Emberfall 2026 (Renamed)', $entry->after_json['name']);
    }

    public function test_a_slug_already_used_in_the_organization_is_refused(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall',
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-event', [
                'organization_id' => (string) $organization->id,
                'name' => 'Another Emberfall',
                'slug' => 'emberfall',
                'timezone' => 'UTC',
            ])
            ->assertStatus(422);

        $this->assertSame(1, Event::query()->where('slug', 'emberfall')->count());
    }

    public function test_an_event_cannot_be_saved_ending_before_it_starts(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-event', [
                'organization_id' => (string) $organization->id,
                'name' => 'Backwards',
                'slug' => 'backwards',
                'timezone' => 'UTC',
                'starts_at' => '2027-09-08T00:00:00Z',
                'ends_at' => '2027-09-01T00:00:00Z',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('events', ['slug' => 'backwards']);
    }

    /** ORG-006: only a department actively assigned to the event may run IC. */
    public function test_the_incident_command_override_takes_a_participating_department_and_refuses_another(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'UTC',
        ]);

        $participating = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        EventDepartmentAssignment::query()->create([
            'event_id' => $event->id,
            'department_id' => $participating->id,
        ]);

        $absent = Department::factory()->for($organization)->create(['name' => 'Gate']);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => 'Emberfall 2026',
                'slug' => 'emberfall-2026',
                'timezone' => 'UTC',
                'ic_department_id' => (string) $participating->id,
            ])
            ->assertOk()
            ->assertJsonPath('events.0.ic_department_id', (string) $participating->id)
            ->assertJsonPath('events.0.effective_ic_department.inherited', false);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => 'Emberfall 2026',
                'slug' => 'emberfall-2026',
                'timezone' => 'UTC',
                'ic_department_id' => (string) $absent->id,
            ])
            ->assertStatus(422);

        $this->assertSame((string) $participating->id, (string) $event->refresh()->ic_department_id);
    }

    /**
     * ORG-005: an event that names no override runs on the organization
     * default, and the surface is told so rather than being left to infer it
     * from a blank.
     */
    public function test_an_event_with_no_override_reports_the_inherited_organization_default(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $default = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $organization->forceFill(['default_ic_department_id' => $default->id])->save();

        Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
        ]);

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/events")
            ->assertOk()
            ->assertJsonPath('default_ic_department.name', 'Rangers')
            ->assertJsonPath('events.0.ic_department_id', null)
            ->assertJsonPath('events.0.effective_ic_department.name', 'Rangers')
            ->assertJsonPath('events.0.effective_ic_department.inherited', true);
    }

    /**
     * A request that never mentions Incident Command leaves the designation
     * alone, and one carrying an explicit null clears it.
     */
    public function test_an_omitted_incident_command_key_leaves_the_designation_alone(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'UTC',
        ]);

        $department = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        EventDepartmentAssignment::query()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        $event->forceFill(['ic_department_id' => $department->id])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => 'Emberfall 2026 Renamed',
                'slug' => 'emberfall-2026',
                'timezone' => 'UTC',
            ])
            ->assertOk();

        $this->assertSame((string) $department->id, (string) $event->refresh()->ic_department_id);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => 'Emberfall 2026 Renamed',
                'slug' => 'emberfall-2026',
                'timezone' => 'UTC',
                'ic_department_id' => null,
            ])
            ->assertOk();

        $this->assertNull($event->refresh()->ic_department_id);
    }

    /**
     * M12.6: every other event-scoped record moves to the on-site primary node
     * during the active event window, and `EventScopedWriteGuard` exempts the
     * `events` row itself "so an organizer can still close or extend the window
     * that makes the node read-only in the first place". The product surface
     * keeps that exemption: refusing here would leave a window nobody could
     * close from the node they are standing at.
     */
    public function test_an_event_inside_its_active_window_can_still_have_that_window_closed(): void
    {
        [$organization, $organizer] = $this->organizerScaffold();

        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'UTC',
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDays(3),
        ]);

        // A paired on-site node, so the event really is somebody else's to write
        // and the read says so.
        $onsite = \App\Models\Node::factory()->create([
            'node_role' => \App\Models\Node::ROLE_ONSITE,
            'node_name' => 'Ranger HQ',
            'event_id' => $event->id,
            'paired_at' => now(),
        ]);

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/events")
            ->assertOk()
            ->assertJsonPath('events.0.authority.phase', 'active')
            ->assertJsonPath('events.0.authority.authoritative_node', $onsite->node_name);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => 'Emberfall 2026',
                'slug' => 'emberfall-2026',
                'timezone' => 'UTC',
                'active_event_window_starts_at' => $event->active_event_window_starts_at->toIso8601String(),
                'active_event_window_ends_at' => now()->subMinute()->toIso8601String(),
            ])
            ->assertOk();

        $this->assertTrue($event->refresh()->active_event_window_ends_at->isPast());
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organizerScaffold(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        return [
            $organization->refresh(),
            $this->userHoldingRoleIn($organization, PermissionCatalog::ROLE_ORGANIZER),
        ];
    }

    private function userHoldingRoleIn(Organization $organization, string $roleCode): User
    {
        $department = Department::query()
            ->where('organization_id', $organization->id)
            ->firstOr(fn (): Department => Department::factory()->for($organization)->create());

        $user = User::factory()->create();
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $team = Team::factory()->for($department)->create(['name' => $roleCode.' team']);
        $membership = DepartmentMembership::factory()->for($department)->for($staff)->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()
                ->where('code', $roleCode)
                ->firstOrFail()->id,
        ]);

        return $user;
    }
}
