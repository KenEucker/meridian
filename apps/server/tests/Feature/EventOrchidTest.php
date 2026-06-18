<?php

namespace Tests\Feature;

use App\Models\Event;
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
            'name' => 'Idaho Burners',
        ]);

        Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2026',
            'slug' => 'idaho-decompression-2026',
            'timezone' => 'America/Denver',
        ]);

        $response = $this->actingAs($this->eventAdmin())->get(route('platform.events'));

        $response->assertOk();
        $response->assertSee('Events');
        $response->assertSee('Idaho Decompression 2026');
        $response->assertSee('idaho-decompression-2026');
        $response->assertSee('Idaho Burners');
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
                    'name' => 'Idaho Decompression 2026',
                    'slug' => 'idaho-decompression-2026',
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
            'name' => 'Idaho Decompression 2026',
            'slug' => 'idaho-decompression-2026',
            'timezone' => 'America/Denver',
        ]);

        $event = Event::query()->where('slug', 'idaho-decompression-2026')->firstOrFail();

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
