<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('events'));
        $this->assertTrue(Schema::hasColumn('events', 'id'));
        $this->assertTrue(Schema::hasColumn('events', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('events', 'name'));
        $this->assertTrue(Schema::hasColumn('events', 'slug'));
        $this->assertTrue(Schema::hasColumn('events', 'starts_at'));
        $this->assertTrue(Schema::hasColumn('events', 'ends_at'));
        $this->assertTrue(Schema::hasColumn('events', 'timezone'));
        $this->assertTrue(Schema::hasColumn('events', 'status'));
        $this->assertTrue(Schema::hasColumn('events', 'ic_department_id'));
        $this->assertTrue(Schema::hasColumn('events', 'active_event_window_starts_at'));
        $this->assertTrue(Schema::hasColumn('events', 'active_event_window_ends_at'));
        $this->assertTrue(Schema::hasColumn('events', 'created_at'));
        $this->assertTrue(Schema::hasColumn('events', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('events', 'archived_at'));
    }

    public function test_event_ids_are_uuids(): void
    {
        $event = Event::factory()->create();

        $this->assertTrue(Str::isUuid($event->id));
    }

    public function test_event_belongs_to_organization_and_organization_has_many_events(): void
    {
        $organization = Organization::factory()->create();
        $firstEvent = Event::factory()->for($organization)->create();
        $secondEvent = Event::factory()->for($organization)->create();

        $organization->refresh()->load('events');
        $firstEvent->refresh()->load('organization');

        $this->assertTrue($firstEvent->organization->is($organization));
        $this->assertCount(2, $organization->events);
        $this->assertTrue($organization->events->contains($firstEvent));
        $this->assertTrue($organization->events->contains($secondEvent));
    }

    public function test_event_time_timezone_status_and_active_window_are_persisted(): void
    {
        $startsAt = Carbon::parse('2026-10-01 09:00:00');
        $endsAt = Carbon::parse('2026-10-04 18:00:00');
        $activeWindowStartsAt = Carbon::parse('2026-09-30 09:00:00');
        $activeWindowEndsAt = Carbon::parse('2026-10-05 18:00:00');

        $event = Event::factory()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => 'America/Denver',
            'status' => 'event-status-placeholder',
            'ic_department_id' => 42,
            'active_event_window_starts_at' => $activeWindowStartsAt,
            'active_event_window_ends_at' => $activeWindowEndsAt,
        ]);

        $this->assertSame($startsAt->toDateTimeString(), $event->starts_at->toDateTimeString());
        $this->assertSame($endsAt->toDateTimeString(), $event->ends_at->toDateTimeString());
        $this->assertSame('America/Denver', $event->timezone);
        $this->assertSame('event-status-placeholder', $event->status);
        $this->assertSame(42, $event->ic_department_id);
        $this->assertSame(
            $activeWindowStartsAt->toDateTimeString(),
            $event->active_event_window_starts_at->toDateTimeString()
        );
        $this->assertSame(
            $activeWindowEndsAt->toDateTimeString(),
            $event->active_event_window_ends_at->toDateTimeString()
        );
    }

    public function test_event_schedule_can_be_tbd(): void
    {
        $event = Event::factory()->create([
            'starts_at' => null,
            'ends_at' => null,
            'active_event_window_starts_at' => null,
            'active_event_window_ends_at' => null,
        ]);

        $this->assertNull($event->starts_at);
        $this->assertNull($event->ends_at);
        $this->assertNull($event->active_event_window_starts_at);
        $this->assertNull($event->active_event_window_ends_at);
    }

    public function test_active_scope_excludes_archived_events(): void
    {
        $activeEvent = Event::factory()->create();

        Event::factory()->archived()->create();

        $this->assertTrue(Event::query()->active()->first()->is($activeEvent));
        $this->assertFalse($activeEvent->isArchived());
    }
}
