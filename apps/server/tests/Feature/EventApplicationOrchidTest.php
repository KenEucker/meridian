<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class EventApplicationOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_application_list_displays_applications(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
        ]);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Idaho Decompression 2026',
        ]);

        EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Jordan Reed',
            'applicant_email' => 'jordan@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $response = $this->actingAs($this->applicationAdmin())->get(route('platform.applications'));

        $response->assertOk();
        $response->assertSee('Applications');
        $response->assertSee('Jordan Reed');
        $response->assertSee('jordan@example.org');
        $response->assertSee('Idaho Decompression 2026');
        $response->assertSee('Idaho Burners');
        $response->assertSee('Submitted');
    }

    public function test_orchid_application_detail_displays_review_scaffold(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
        ]);

        $event = Event::factory()->for($organization)->create([
            'name' => 'Signal Camp 2026',
        ]);

        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Casey Reed',
            'applicant_email' => 'casey@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => '2026-06-01 10:00:00',
        ]);

        $response = $this->actingAs($this->applicationAdmin())
            ->get(route('platform.applications.show', $application));

        $response->assertOk();
        $response->assertSee('Review Application');
        $response->assertSee('Casey Reed');
        $response->assertSee('casey@example.org');
        $response->assertSee('Signal Camp 2026');
        $response->assertSee('Idaho Burners');
        $response->assertSee('Submitted');
        $response->assertSee('Approval occurs at the organization level');
        $response->assertSee('Back');
        $response->assertDontSee('Save');
        $response->assertDontSee('Approve');
        $response->assertDontSee('Reject');
    }

    public function test_orchid_application_detail_shows_canonical_status_labels(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_AUTO_REJECTED_DNS,
            'reviewed_at' => '2026-06-02 12:00:00',
            'decision_reason' => 'Applicant email domain matches DNS.',
        ]);

        $response = $this->actingAs($this->applicationAdmin())
            ->get(route('platform.applications.show', $application));

        $response->assertOk();
        $response->assertSee('Auto-rejected due to DNS');
        $response->assertSee('Applicant email domain matches DNS.');
    }

    public function test_orchid_application_screens_require_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $application = EventApplication::factory()->create();

        $this->actingAs($user)->get(route('platform.applications'))->assertForbidden();
        $this->actingAs($user)->get(route('platform.applications.show', $application))->assertForbidden();
    }

    private function applicationAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.applications' => true,
            ],
        ]);
    }
}
