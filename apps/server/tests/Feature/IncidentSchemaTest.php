<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class IncidentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_incidents_table_has_documented_columns(): void
    {
        $this->assertTrue(Schema::hasTable('incidents'));

        foreach ([
            'id',
            'event_id',
            'incident_number',
            'status',
            'priority_label',
            'started_at',
            'title',
            'location_name',
            'location_address',
            'location_details',
            'camp_id',
            'map_location_id',
            'created_by_user_id',
            'created_at',
            'updated_at',
            'closed_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('incidents', $column),
                "incidents should have a {$column} column.",
            );
        }
    }

    public function test_incidents_use_uuid_primary_keys(): void
    {
        $incident = Incident::factory()->create();

        $this->assertFalse($incident->getIncrementing());
        $this->assertSame('string', $incident->getKeyType());
        $this->assertTrue(Str::isUuid($incident->getKey()));
    }

    public function test_incident_belongs_to_event_and_creator(): void
    {
        $event = Event::factory()->create();
        $creator = User::factory()->create();

        $incident = Incident::factory()->create([
            'event_id' => $event->id,
            'incident_number' => 'INC-2027-000123',
            'created_by_user_id' => $creator->id,
            'title' => 'Medical assist near Gate A',
            'location_name' => 'Gate A',
            'location_address' => '100 Center Camp Road',
            'location_details' => 'North side of the gate.',
        ]);

        $incident->refresh()->load(['event', 'createdByUser']);

        $this->assertTrue($incident->event->is($event));
        $this->assertTrue($incident->createdByUser->is($creator));
        $this->assertSame('Medical assist near Gate A', $incident->title);
        $this->assertSame('Gate A', $incident->location_name);
        $this->assertSame('100 Center Camp Road', $incident->location_address);
        $this->assertSame('North side of the gate.', $incident->location_details);
        $this->assertTrue($event->incidents->contains($incident));
        $this->assertTrue($creator->createdIncidents->contains($incident));
    }

    public function test_incidents_are_event_specific(): void
    {
        $eventA = Event::factory()->create();
        $eventB = Event::factory()->create();

        $eventAIncident = Incident::factory()->create([
            'event_id' => $eventA->id,
            'incident_number' => 'INC-2027-000001',
        ]);
        Incident::factory()->create([
            'event_id' => $eventB->id,
            'incident_number' => 'INC-2027-000001',
        ]);

        $eventAIds = Incident::query()
            ->forEvent($eventA)
            ->pluck('id')
            ->all();

        $this->assertSame([$eventAIncident->id], $eventAIds);
    }

    public function test_incidents_expose_fixed_alpha_statuses(): void
    {
        $this->assertSame([
            'open',
            'on_scene',
            'monitoring',
            'on_hold',
            'closed',
        ], Incident::statuses());
    }

    public function test_incidents_expose_fixed_alpha_priority_labels(): void
    {
        $this->assertSame([
            'Routine',
            'Important',
            'Serious',
            'Critical',
        ], Incident::priorityLabels());
    }

    public function test_incident_priority_types_and_staff_storage_exist(): void
    {
        $this->assertTrue(Schema::hasTable('incident_types'));
        $this->assertTrue(Schema::hasTable('incident_incident_types'));
        $this->assertTrue(Schema::hasTable('incident_staff'));

        foreach (['id', 'organization_id', 'name', 'created_at', 'archived_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('incident_types', $column));
        }

        foreach (['id', 'incident_id', 'incident_type_id', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('incident_incident_types', $column));
        }

        foreach (['id', 'incident_id', 'staff_id', 'relationship_label', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('incident_staff', $column));
        }
    }

    public function test_incidents_cannot_be_deleted(): void
    {
        $incident = Incident::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incidents are operational history and cannot be deleted.');

        $incident->delete();
    }
}
