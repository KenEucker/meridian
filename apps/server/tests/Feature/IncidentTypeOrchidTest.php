<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God Mode incident type administration (M18.14A).
 */
class IncidentTypeOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_incident_type_list_shows_types_with_their_use(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northwood Collective']);
        $event = Event::factory()->for($organization)->create();
        $type = IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Medical',
        ]);
        IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Retired category',
            'archived_at' => Carbon::parse('2027-07-01T00:00:00Z'),
        ]);

        Incident::factory()->forEvent($event)->create()->incidentTypes()->attach($type->id, [
            'id' => (string) Str::uuid(),
            'created_at' => Carbon::parse('2027-07-04T20:00:00Z'),
        ]);

        $response = $this->actingAs($this->incidentTypeAdmin())
            ->get(route('platform.incident-types'));

        $response->assertOk();
        $response->assertSee('Incident types');
        $response->assertSee('Medical');
        $response->assertSee('Northwood Collective');
        $response->assertSee('Retired category');
        $response->assertSee('Archived');
    }

    public function test_orchid_creates_a_type_through_the_admin_service(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->actingAs($this->incidentTypeAdmin())
            ->from(route('platform.incident-types.create'))
            ->post(route('platform.incident-types.create', ['method' => 'save']), [
                'incidentType' => [
                    'organization_id' => $organization->id,
                    'name' => 'Camp Dispute',
                ],
            ]);

        $response->assertRedirect(route('platform.incident-types'));

        $type = IncidentType::query()->where('name', 'Camp Dispute')->first();

        $this->assertNotNull($type);
        // The Orchid path writes the same audit row the product path does.
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'incident_type.created')
                ->where('entity_id', $type->id)
                ->where('source_context', AuditEvent::SOURCE_ORCHID)
                ->exists(),
        );
    }

    public function test_orchid_refuses_a_duplicate_name(): void
    {
        $organization = Organization::factory()->create();
        IncidentType::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Medical',
        ]);

        $response = $this->actingAs($this->incidentTypeAdmin())
            ->from(route('platform.incident-types.create'))
            ->post(route('platform.incident-types.create', ['method' => 'save']), [
                'incidentType' => [
                    'organization_id' => $organization->id,
                    'name' => 'medical',
                ],
            ]);

        $response->assertSessionHasErrors('incidentType.name');
        $this->assertSame(
            1,
            IncidentType::query()->where('organization_id', $organization->id)->count(),
        );
    }

    public function test_orchid_archives_and_restores_a_type(): void
    {
        $type = IncidentType::factory()->create(['name' => 'Weather']);
        $admin = $this->incidentTypeAdmin();

        $this->actingAs($admin)
            ->from(route('platform.incident-types.edit', $type->id))
            ->post(route('platform.incident-types.edit', ['incidentType' => $type->id, 'method' => 'archive']))
            ->assertRedirect(route('platform.incident-types'));

        $this->assertNotNull($type->refresh()->archived_at);

        $this->actingAs($admin)
            ->from(route('platform.incident-types.edit', $type->id))
            ->post(route('platform.incident-types.edit', ['incidentType' => $type->id, 'method' => 'restore']))
            ->assertRedirect(route('platform.incident-types'));

        $this->assertNull($type->refresh()->archived_at);
    }

    public function test_an_operator_without_the_permission_cannot_reach_the_screen(): void
    {
        $withoutPermission = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($withoutPermission)
            ->get(route('platform.incident-types'))
            ->assertForbidden();
    }

    private function incidentTypeAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.incident-types' => true,
            ],
        ]);
    }
}
