<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class EventOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_event_list_displays_events(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
        ]);

        Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'America/Denver',
        ]);

        $response = $this->actingAs($this->eventAdmin())->get(route('platform.events'));

        $response->assertOk();
        $response->assertSee('Events');
        $response->assertSee('Emberfall 2026');
        $response->assertSee('emberfall-2026');
        $response->assertSee('Northwood Collective');
        $response->assertSee('America/Denver');
    }

    public function test_orchid_event_detail_displays_edit_scaffold(): void
    {
        $event = Event::factory()->create([
            'name' => 'Signal Camp 2026',
            'slug' => 'signal-camp-2026',
        ]);

        $response = $this->actingAs($this->eventAdmin())
            ->get(route('platform.events.edit', $event));

        $response->assertOk();
        $response->assertSee('Edit Event');
        $response->assertSee('Signal Camp 2026');
        $response->assertSee('signal-camp-2026');
        $response->assertSee('name="event[timezone]"', false);
        $response->assertSee('name="event[ic_department_id]"', false);
        $response->assertSee('<option value="America/Denver"', false);
        $response->assertSee('Leave blank when the event schedule is TBD.');
        $response->assertSee('meridian-admin.js');
        $response->assertSee('data-meridian-slug-target="event[slug]"', false);
        $response->assertSee('data-meridian-slug-year-source="event[starts_at]"', false);
        $response->assertSee('Save');
        $response->assertSee('Cancel');
    }

    public function test_orchid_event_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.events'));

        $response->assertForbidden();
    }

    public function test_orchid_event_save_creates_event(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->screen('platform.events.create')
            ->actingAs($this->eventAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'event' => [
                    'organization_id' => $organization->id,
                    'name' => 'Emberfall 2026',
                    'slug' => 'emberfall-2026',
                    'timezone' => 'America/Denver',
                    'starts_at' => '2026-10-01 09:00:00',
                    'ends_at' => '2026-10-04 18:00:00',
                    'active_event_window_starts_at' => '2026-09-30 09:00:00',
                    'active_event_window_ends_at' => '2026-10-05 18:00:00',
                ],
            ]);

        $response->assertRedirect(route('platform.events'));

        $this->assertDatabaseHas('events', [
            'organization_id' => $organization->id,
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'America/Denver',
        ]);

        $event = Event::query()->where('slug', 'emberfall-2026')->firstOrFail();

        $this->assertSame('2026-10-01 09:00:00', $event->starts_at->toDateTimeString());
        $this->assertSame('2026-10-04 18:00:00', $event->ends_at->toDateTimeString());
        $this->assertSame('2026-09-30 09:00:00', $event->active_event_window_starts_at->toDateTimeString());
        $this->assertSame('2026-10-05 18:00:00', $event->active_event_window_ends_at->toDateTimeString());
    }

    public function test_orchid_event_save_can_create_tbd_event_without_dates(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->screen('platform.events.create')
            ->actingAs($this->eventAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'event' => [
                    'organization_id' => $organization->id,
                    'name' => 'TBD Event',
                    'slug' => 'tbd-event',
                    'timezone' => 'America/Denver',
                ],
            ]);

        $response->assertRedirect(route('platform.events'));

        $event = Event::query()->where('slug', 'tbd-event')->firstOrFail();

        $this->assertNull($event->starts_at);
        $this->assertNull($event->ends_at);
    }

    public function test_orchid_event_list_displays_tbd_for_unscheduled_events(): void
    {
        Event::factory()->create([
            'name' => 'TBD Event',
            'slug' => 'tbd-event',
            'starts_at' => null,
            'ends_at' => null,
        ]);

        $response = $this->actingAs($this->eventAdmin())->get(route('platform.events'));

        $response->assertOk();
        $response->assertSee('TBD Event');
        $response->assertSee('TBD');
    }

    public function test_orchid_event_save_validates_chronological_time_window(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->screen('platform.events.create')
            ->actingAs($this->eventAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'event' => [
                    'organization_id' => $organization->id,
                    'name' => 'Invalid Event',
                    'slug' => 'invalid-event',
                    'timezone' => 'America/Denver',
                    'starts_at' => '2026-10-04 18:00:00',
                    'ends_at' => '2026-10-01 09:00:00',
                ],
            ]);

        $response->assertSessionHasErrors('event.ends_at');
    }

    public function test_orchid_event_detail_lists_only_active_participating_ic_department_options(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $rangers = Department::factory()->for($organization)->create(['name' => 'Rangers']);
        $gate = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $archived = Department::factory()->for($organization)->archived()->create(['name' => 'Archived Ops']);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $rangers->id,
        ]);
        EventDepartmentAssignment::factory()->archived()->create([
            'event_id' => $event->id,
            'department_id' => $gate->id,
        ]);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $archived->id,
        ]);

        $response = $this->actingAs($this->eventAdmin())
            ->get(route('platform.events.edit', $event));

        $response->assertOk();
        $response->assertSee('Rangers');
        $response->assertDontSee('Gate');
        $response->assertDontSee('Archived Ops');
    }

    public function test_orchid_event_save_configures_ic_department_with_audit(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create([
            'name' => 'Emberfall 2026',
            'slug' => 'emberfall-2026',
            'timezone' => 'America/Denver',
        ]);
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        $response = $this->screen('platform.events.edit', [
            'event' => $event->id,
        ])
            ->actingAs($this->eventAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'event' => [
                    'organization_id' => $organization->id,
                    'name' => 'Emberfall 2026',
                    'slug' => 'emberfall-2026',
                    'timezone' => 'America/Denver',
                    'ic_department_id' => $department->id,
                ],
            ]);

        $response->assertRedirect(route('platform.events'));

        $this->assertSame($department->id, $event->refresh()->ic_department_id);

        $audit = AuditEvent::query()->where('action', 'event.ic_department_changed')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($event->id, $audit->event_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
    }

    public function test_orchid_event_save_rejects_unassigned_ic_department(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();

        $response = $this->screen('platform.events.edit', [
            'event' => $event->id,
        ])
            ->actingAs($this->eventAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'event' => [
                    'organization_id' => $organization->id,
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'timezone' => $event->timezone,
                    'ic_department_id' => $department->id,
                ],
            ]);

        $response->assertSessionHasErrors('event.ic_department_id');
        $this->assertNull($event->refresh()->ic_department_id);
    }

    private function eventAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.events' => true,
            ],
        ]);
    }
}
