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
 * Which departments participate in an event (M18.31; ORG-006; PLACE-003;
 * data/API 10.2, 10.6).
 */
class EventDepartmentParticipationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_adds_a_department_to_an_event_and_the_addition_is_audited(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/assign-department-to-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertOk()
            ->assertJsonPath('events.0.participating_departments.0.name', 'Rangers');

        $this->assertDatabaseHas('event_department_assignments', [
            'event_id' => (string) $event->id,
            'department_id' => (string) $rangers->id,
            'archived_at' => null,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'event.department_assigned',
            'entity_id' => (string) $event->id,
            'actor_user_id' => $organizer->id,
            'department_id' => (string) $rangers->id,
        ]);
    }

    public function test_adding_a_department_twice_leaves_one_assignment_and_one_entry(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);

        foreach ([1, 2] as $ignored) {
            $this->actingAsClient($organizer)
                ->postJson('/api/commands/assign-department-to-event', [
                    'event_id' => (string) $event->id,
                    'department_id' => (string) $rangers->id,
                ])
                ->assertOk();
        }

        $this->assertSame(1, EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $rangers->id)
            ->count());

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'event.department_assigned')
            ->where('entity_id', (string) $event->id)
            ->count());
    }

    /** A department belongs to one organization, and so does the event it works. */
    public function test_a_department_of_another_organization_is_refused(): void
    {
        [, $organizer, $event] = $this->eventScaffold();
        $somebodyElse = Department::factory()
            ->for(Organization::factory()->create())
            ->create(['name' => 'Somebody Else\'s Rangers']);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/assign-department-to-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $somebodyElse->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('event_department_assignments', [
            'event_id' => (string) $event->id,
            'department_id' => (string) $somebodyElse->id,
        ]);
    }

    public function test_an_archived_department_cannot_be_added(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $retired = Department::factory()->for($organization)->archived()->create([
            'name' => 'Retired Crew',
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/assign-department-to-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $retired->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('event_department_assignments', [
            'event_id' => (string) $event->id,
            'department_id' => (string) $retired->id,
        ]);
    }

    /**
     * Removal archives the row. A department that worked an event worked it,
     * and the records naming it are read through history that has to stay.
     */
    public function test_removing_a_department_archives_its_assignment_and_is_audited(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertOk()
            ->assertJsonCount(0, 'events.0.participating_departments');

        $assignment = EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $rangers->id)
            ->firstOrFail();

        $this->assertTrue($assignment->isArchived());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'event.department_removed',
            'entity_id' => (string) $event->id,
            'department_id' => (string) $rangers->id,
        ]);
    }

    public function test_a_removed_department_is_restored_rather_than_assigned_twice(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertOk();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/assign-department-to-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertOk()
            ->assertJsonPath('events.0.participating_departments.0.name', 'Rangers');

        $this->assertSame(1, EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $rangers->id)
            ->count());
    }

    /**
     * ORG-006: the Incident Command designation admits only a department
     * assigned to the event, which is a rule about the department leaving as
     * much as about it being chosen.
     */
    public function test_the_incident_command_department_cannot_be_removed_while_designated(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');
        $event->forceFill(['ic_department_id' => $rangers->id])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Rangers runs Incident Command for this event. Designate another department, or clear the designation, and then remove it.');

        $this->assertFalse(EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $rangers->id)
            ->firstOrFail()
            ->isArchived());
    }

    /** PLACE-003: the same rule, read from the Placement designation. */
    public function test_the_placement_department_cannot_be_removed_while_designated(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $dpw = $this->participating($event, $organization, 'DPW');
        $event->forceFill(['placement_department_id' => $dpw->id])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $dpw->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'DPW runs Placement for this event. Designate another department, or clear the designation, and then remove it.');

        $this->assertFalse(EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $dpw->id)
            ->firstOrFail()
            ->isArchived());
    }

    public function test_a_department_can_be_removed_once_its_designation_is_cleared(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');
        $event->forceFill(['ic_department_id' => $rangers->id])->save();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/update-event', [
                'event_id' => (string) $event->id,
                'name' => (string) $event->name,
                'slug' => (string) $event->slug,
                'timezone' => (string) $event->timezone,
                'ic_department_id' => null,
            ])
            ->assertOk();

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertOk();

        $this->assertTrue(EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $rangers->id)
            ->firstOrFail()
            ->isArchived());
    }

    public function test_a_department_that_does_not_participate_cannot_be_removed(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $absent = Department::factory()->for($organization)->create(['name' => 'Gate']);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $absent->id,
            ])
            ->assertStatus(422);
    }

    /**
     * The read carries the designation labels and the node's own refusal
     * sentence, so the surface explains a removal it will not offer with the
     * words the command would have answered with.
     */
    public function test_the_read_names_each_designation_and_why_a_department_cannot_leave(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');
        $gate = $this->participating($event, $organization, 'Gate');
        $event->forceFill([
            'ic_department_id' => $rangers->id,
            'placement_department_id' => $gate->id,
        ])->save();

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/events")
            ->assertOk();

        $departments = collect($response->json('events.0.participating_departments'))
            ->keyBy('name');

        $this->assertTrue($departments['Rangers']['is_incident_command']);
        $this->assertFalse($departments['Rangers']['is_placement']);
        $this->assertStringContainsString(
            'runs Incident Command',
            (string) $departments['Rangers']['removal_refusal'],
        );

        $this->assertTrue($departments['Gate']['is_placement']);
        $this->assertStringContainsString(
            'runs Placement',
            (string) $departments['Gate']['removal_refusal'],
        );

        $this->assertSame('Gate', $response->json('events.0.placement_department.name'));
    }

    /**
     * The departments that could join, which is every active department of the
     * organization that is not already in the event.
     */
    public function test_the_read_offers_the_organizations_other_active_departments(): void
    {
        [$organization, $organizer, $event] = $this->eventScaffold();
        $this->participating($event, $organization, 'Rangers');
        Department::factory()->for($organization)->create(['name' => 'Gate']);
        Department::factory()->for($organization)->archived()->create(['name' => 'Retired Crew']);
        Department::factory()
            ->for(Organization::factory()->create())
            ->create(['name' => 'Somebody Else\'s Gate']);

        $response = $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/events")
            ->assertOk();

        $assignable = collect($response->json('events.0.assignable_departments'))
            ->pluck('name')
            ->all();

        // The Organizers department the scaffold creates is assignable too; the
        // point of the assertion is what is absent.
        $this->assertContains('Gate', $assignable);
        $this->assertNotContains('Rangers', $assignable);
        $this->assertNotContains('Retired Crew', $assignable);
        $this->assertNotContains('Somebody Else\'s Gate', $assignable);
    }

    public function test_a_caller_without_the_capability_changes_no_participation(): void
    {
        [$organization, , $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');
        $outsider = User::factory()->create();

        $this->actingAsClient($outsider)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertForbidden();

        $this->actingAsClient($outsider)
            ->postJson('/api/commands/assign-department-to-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertForbidden();

        $this->assertFalse(EventDepartmentAssignment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $rangers->id)
            ->firstOrFail()
            ->isArchived());
    }

    /**
     * TEAM-014: the Staff Coordinator carries no organizer governance
     * capability, and who works an event is governance.
     */
    public function test_a_staff_coordinator_changes_no_participation(): void
    {
        [$organization, , $event] = $this->eventScaffold();
        $rangers = $this->participating($event, $organization, 'Rangers');
        $coordinator = $this->userHoldingRoleIn(
            $organization,
            PermissionCatalog::ROLE_STAFF_COORDINATOR,
        );

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/remove-department-from-event', [
                'event_id' => (string) $event->id,
                'department_id' => (string) $rangers->id,
            ])
            ->assertForbidden();
    }

    private function participating(Event $event, Organization $organization, string $name): Department
    {
        $department = Department::factory()->for($organization)->create(['name' => $name]);

        EventDepartmentAssignment::query()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        return $department;
    }

    /**
     * @return array{0: Organization, 1: User, 2: Event}
     */
    private function eventScaffold(): array
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'UTC',
        ]);

        return [
            $organization->refresh(),
            $this->userHoldingRoleIn($organization, PermissionCatalog::ROLE_ORGANIZER),
            $event,
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
