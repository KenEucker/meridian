<?php

namespace Tests\Feature;

use App\Exceptions\IncidentCreationException;
use App\Models\Event;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\IncidentCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_incident_creation_assigns_ims_number_and_defaults_open_status(): void
    {
        Carbon::setTestNow('2027-07-04 20:30:00 UTC');
        $actor = User::factory()->create();
        $event = Event::factory()->create([
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);

        $incident = app(IncidentCreationService::class)->create([
            'event_id' => $event->id,
            'title' => '  Medical assist near Gate A  ',
            'started_at' => '2027-07-04 20:15:00 UTC',
            'location_name' => '  Gate A  ',
            'location_address' => '',
            'location_details' => 'North side of the gate.',
        ], $actor);

        $this->assertSame('INC-2027-000001', $incident->incident_number);
        $this->assertSame(Incident::STATUS_OPEN, $incident->status);
        $this->assertSame('Medical assist near Gate A', $incident->title);
        $this->assertSame('Gate A', $incident->location_name);
        $this->assertNull($incident->location_address);
        $this->assertSame('North side of the gate.', $incident->location_details);
        $this->assertNull($incident->closed_at);
        $this->assertTrue($incident->created_at->equalTo(now()));
        $this->assertTrue($incident->started_at->equalTo(Carbon::parse('2027-07-04 20:15:00 UTC')));
        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'event_id' => $event->id,
            'incident_number' => 'INC-2027-000001',
            'created_by_user_id' => $actor->id,
        ]);
    }

    public function test_incident_numbers_are_monotonic_and_event_specific(): void
    {
        $actor = User::factory()->create();
        $eventA = Event::factory()->create([
            'starts_at' => Carbon::parse('2027-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);
        $eventB = Event::factory()->create([
            'starts_at' => Carbon::parse('2028-07-04 09:00:00', 'America/Los_Angeles')->utc(),
            'timezone' => 'America/Los_Angeles',
        ]);
        $service = app(IncidentCreationService::class);

        $first = $service->create([
            'event_id' => $eventA->id,
            'title' => 'First incident',
        ], $actor);
        $second = $service->create([
            'event_id' => $eventA->id,
            'title' => 'Second incident',
        ], $actor);
        $otherEvent = $service->create([
            'event_id' => $eventB->id,
            'title' => 'Other event incident',
        ], $actor);

        $this->assertSame('INC-2027-000001', $first->incident_number);
        $this->assertSame('INC-2027-000002', $second->incident_number);
        $this->assertSame('INC-2028-000001', $otherEvent->incident_number);
    }

    public function test_incident_number_assignment_uses_event_timezone_year(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->create([
            'starts_at' => Carbon::parse('2028-01-01 02:00:00 UTC'),
            'timezone' => 'America/Los_Angeles',
        ]);

        $incident = app(IncidentCreationService::class)->create([
            'event_id' => $event->id,
            'title' => 'Timezone boundary incident',
        ], $actor);

        $this->assertSame('INC-2027-000001', $incident->incident_number);
    }

    public function test_incident_creation_validates_fixed_status(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->create();
        $service = app(IncidentCreationService::class);

        $this->expectException(IncidentCreationException::class);
        $this->expectExceptionMessage('Incident status is invalid.');

        $service->create([
            'event_id' => $event->id,
            'title' => 'Invalid status incident',
            'status' => 'dispatched',
        ], $actor);
    }

    public function test_incident_creation_allows_blank_title(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->create();

        $incident = app(IncidentCreationService::class)->create([
            'event_id' => $event->id,
            'title' => '   ',
        ], $actor);

        $this->assertSame('', $incident->title);
        $this->assertMatchesRegularExpression('/^INC-\d{4}-000001$/', $incident->incident_number);
    }

    public function test_incident_creation_requires_scheduled_event_for_number_assignment(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->create(['starts_at' => null]);

        $this->expectException(IncidentCreationException::class);
        $this->expectExceptionMessage('Incident event must have a scheduled start and timezone before IMS number assignment.');

        app(IncidentCreationService::class)->create([
            'event_id' => $event->id,
            'title' => 'Missing schedule incident',
        ], $actor);
    }

    public function test_incident_creation_allows_optional_map_references_without_requiring_them(): void
    {
        $actor = User::factory()->create();
        $event = Event::factory()->create();
        $campId = (string) Str::uuid();
        $mapLocationId = (string) Str::uuid();

        $incident = app(IncidentCreationService::class)->create([
            'event_id' => $event->id,
            'title' => 'Camp reference incident',
            'camp_id' => $campId,
            'map_location_id' => $mapLocationId,
        ], $actor);

        $this->assertSame($campId, $incident->camp_id);
        $this->assertSame($mapLocationId, $incident->map_location_id);
    }
}
