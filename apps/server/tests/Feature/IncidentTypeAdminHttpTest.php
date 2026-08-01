<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Organization incident type administration (M18.14A; ORG-018, ORG-020;
 * INC-007).
 */
class IncidentTypeAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_lists_their_organizations_types_with_archived_ones(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        $active = $this->type($organization, 'Medical');
        $archived = $this->type($organization, 'Weather', archived: true);
        $this->type($other, 'Other organization type');

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/incident-types")
            ->assertOk()
            ->assertJsonCount(2, 'incident_types')
            ->assertJsonPath('incident_types.0.id', $active->id)
            ->assertJsonPath('incident_types.0.archived', false)
            ->assertJsonPath('incident_types.1.id', $archived->id)
            // Archived rows are the point of this read: restoring one needs it
            // to be visible.
            ->assertJsonPath('incident_types.1.archived', true)
            ->assertJsonMissing(['name' => 'Other organization type']);
    }

    public function test_the_read_counts_the_incidents_that_already_carry_a_type(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $type = $this->type($organization, 'Medical');
        $event = Event::factory()->for($organization)->create();

        foreach (range(1, 2) as $index) {
            $incident = Incident::factory()->forEvent($event)->create([
                'incident_number' => sprintf('INC-2027-00000%d', $index),
            ]);
            $incident->incidentTypes()->attach($type->id, [
                'id' => (string) Str::uuid(),
                'created_at' => Carbon::parse('2027-07-04T20:00:00Z'),
            ]);
        }

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/incident-types")
            ->assertOk()
            ->assertJsonPath('incident_types.0.incident_count', 2);
    }

    public function test_an_organizer_creates_renames_archives_and_restores_a_type(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        $created = $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-incident-type', [
                'organization_id' => $organization->id,
                'name' => '  Camp Dispute  ',
            ])
            ->assertCreated()
            ->json('id');

        $this->assertSame('Camp Dispute', IncidentType::query()->find($created)?->name);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/rename-incident-type', [
                'incident_type_id' => $created,
                'name' => 'Camp Conflict',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Camp Conflict');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-incident-type', ['incident_type_id' => $created])
            ->assertOk()
            ->assertJsonPath('archived', true);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/restore-incident-type', ['incident_type_id' => $created])
            ->assertOk()
            ->assertJsonPath('archived', false);

        foreach ([
            'incident_type.created',
            'incident_type.renamed',
            'incident_type.archived',
            'incident_type.restored',
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

    public function test_archiving_keeps_the_type_on_the_incidents_that_carry_it(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $type = $this->type($organization, 'Medical');
        $event = Event::factory()->for($organization)->create();
        $incident = Incident::factory()->forEvent($event)->create();

        $incident->incidentTypes()->attach($type->id, [
            'id' => (string) Str::uuid(),
            'created_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/archive-incident-type', ['incident_type_id' => $type->id])
            ->assertOk();

        // Archived, not deleted: what was recorded about the incident stands.
        $this->assertNotNull(IncidentType::query()->find($type->id)?->archived_at);
        $this->assertSame(
            ['Medical'],
            $incident->refresh()->incidentTypes->pluck('name')->all(),
        );
    }

    public function test_a_duplicate_name_is_refused_whatever_its_casing(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);
        $this->type($organization, 'Medical');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-incident-type', [
                'organization_id' => $organization->id,
                'name' => 'medical',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This organization already has an incident type named medical.');

        // Including against an archived row, which still holds the name.
        $archived = $this->type($organization, 'Weather', archived: true);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-incident-type', [
                'organization_id' => $organization->id,
                'name' => 'WEATHER',
            ])
            ->assertStatus(422);

        $this->assertSame(1, IncidentType::query()->whereKey($archived->id)->count());
    }

    public function test_a_blank_name_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $organizer = $this->organizerFor($organization);

        // Laravel's `required` trims, so a whitespace-only name is refused at
        // validation and never reaches the service. The service keeps its own
        // guard for the Orchid path, which does not go through this request.
        $this->actingAsClient($organizer)
            ->postJson('/api/commands/create-incident-type', [
                'organization_id' => $organization->id,
                'name' => '   ',
            ])
            ->assertStatus(422);

        $this->assertSame(
            0,
            IncidentType::query()->where('organization_id', $organization->id)->count(),
        );

        $this->expectException(\App\Services\Incidents\IncidentTypeAdminException::class);
        $this->expectExceptionMessage('Incident type name is required.');

        app(\App\Services\Incidents\IncidentTypeAdminService::class)
            ->create($organization, '   ', $organizer);
    }

    public function test_an_organizer_of_another_organization_is_refused(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $outsider = $this->organizerFor($other);
        $type = $this->type($organization, 'Medical');

        $this->actingAsClient($outsider)
            ->getJson("/api/organizations/{$organization->id}/incident-types")
            ->assertForbidden();

        $this->actingAsClient($outsider)
            ->postJson('/api/commands/rename-incident-type', [
                'incident_type_id' => $type->id,
                'name' => 'Renamed by an outsider',
            ])
            ->assertForbidden();

        $this->assertSame('Medical', $type->refresh()->name);
    }

    public function test_incident_command_cannot_maintain_the_list(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create(['ic_department_id' => $department->id]);
        $icLead = $this->userWithRole('ic_lead', $organization, $department, $event);
        $type = $this->type($organization, 'Medical');

        // IC decides which type an incident is; the organization decides which
        // types exist.
        $this->actingAsClient($icLead)
            ->getJson("/api/organizations/{$organization->id}/incident-types")
            ->assertForbidden();

        $this->actingAsClient($icLead)
            ->postJson('/api/commands/archive-incident-type', ['incident_type_id' => $type->id])
            ->assertForbidden();

        $this->assertNull($type->refresh()->archived_at);
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/organizations/{$organization->id}/incident-types")->assertUnauthorized();
        $this->postJson('/api/commands/create-incident-type', [
            'organization_id' => $organization->id,
            'name' => 'Medical',
        ])->assertUnauthorized();
    }

    private function type(Organization $organization, string $name, bool $archived = false): IncidentType
    {
        return IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'archived_at' => $archived ? Carbon::parse('2027-07-01T00:00:00Z') : null,
        ]);
    }

    private function organizerFor(Organization $organization): User
    {
        $department = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $department->id])->save();

        return $this->userWithRole('organizer', $organization, $department);
    }

    private function userWithRole(
        string $roleCode,
        Organization $organization,
        Department $department,
        ?Event $event = null,
    ): User {
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
            'event_id' => $event?->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return $user;
    }
}
