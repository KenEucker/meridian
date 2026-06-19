<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApplicationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_application_form_is_reachable_for_an_event(): void
    {
        $event = Event::factory()->create(['name' => 'Idaho Decompression 2026']);

        $response = $this->get(route('public.events.apply', $event->applyRouteParameters()));

        $response->assertOk();
        $response->assertSee('Idaho Decompression 2026');
        $response->assertSee('Submit application');
    }

    public function test_apply_route_is_scoped_by_organization_and_event_slug(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);
        $event = Event::factory()->for($organization)->create(['slug' => 'idaho-decompression-2026']);

        $this->assertSame(
            'http://localhost/idaho-burners/idaho-decompression-2026/apply',
            route('public.events.apply', $event->applyRouteParameters()),
        );

        $this->get('/idaho-burners/idaho-decompression-2026/apply')->assertOk();
        $this->get('/idaho-decompression-2026/apply')->assertNotFound();
        $this->get('/events/idaho-decompression-2026/apply')->assertNotFound();
    }

    public function test_same_event_slug_resolves_to_the_correct_organization(): void
    {
        $firstOrganization = Organization::factory()->create(['slug' => 'org-alpha']);
        $secondOrganization = Organization::factory()->create(['slug' => 'org-beta']);
        $firstEvent = Event::factory()->for($firstOrganization)->create([
            'slug' => 'summer-fest',
            'name' => 'Alpha Summer Fest',
        ]);
        $secondEvent = Event::factory()->for($secondOrganization)->create([
            'slug' => 'summer-fest',
            'name' => 'Beta Summer Fest',
        ]);

        $this->get('/org-alpha/summer-fest/apply')->assertOk()->assertSee('Alpha Summer Fest');
        $this->get('/org-beta/summer-fest/apply')->assertOk()->assertSee('Beta Summer Fest');
        $this->get('/summer-fest/apply')->assertNotFound();

        $this->assertTrue($firstEvent->is($firstOrganization->events()->where('slug', 'summer-fest')->first()));
        $this->assertTrue($secondEvent->is($secondOrganization->events()->where('slug', 'summer-fest')->first()));
    }

    public function test_form_does_not_offer_department_selection(): void
    {
        $event = Event::factory()->create();

        $response = $this->get(route('public.events.apply', $event->applyRouteParameters()));

        // APP-002: applicants apply to events, not directly to departments, so
        // the public form must not offer a department selection control.
        $response->assertDontSee('name="department', false);
        $response->assertDontSee('<select', false);
    }

    public function test_submitting_valid_application_creates_a_submitted_record(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();

        $response = $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'Pat.Prospective@Example.com',
        ]);

        $response->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $this->assertDatabaseHas('event_applications', [
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_email' => 'pat.prospective@example.com',
            'applicant_legal_name' => 'Pat Prospective',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $application = EventApplication::query()->firstOrFail();
        $this->assertNotNull($application->submitted_at);
        $this->assertNull($application->staff_id);
        $this->assertNull($application->reviewed_at);
        $this->assertNull($application->withdrawn_at);
    }

    public function test_submitted_confirmation_page_renders_after_submission(): void
    {
        $event = Event::factory()->create();

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response = $this->get(route('public.events.apply.submitted', $event->applyRouteParameters()));

        $response->assertOk();
        $response->assertSee('Application submitted');
    }

    public function test_validation_rejects_missing_name_and_invalid_email(): void
    {
        $event = Event::factory()->create();

        $response = $this->from(route('public.events.apply', $event->applyRouteParameters()))
            ->post(route('public.events.apply.store', $event->applyRouteParameters()), [
                'applicant_legal_name' => '',
                'applicant_email' => 'not-an-email',
            ]);

        $response->assertRedirect(route('public.events.apply', $event->applyRouteParameters()));
        $response->assertSessionHasErrors(['applicant_legal_name', 'applicant_email']);
        $this->assertDatabaseCount('event_applications', 0);
    }

    public function test_applications_are_blocked_for_archived_events(): void
    {
        $event = Event::factory()->archived()->create();

        $formResponse = $this->get(route('public.events.apply', $event->applyRouteParameters()));
        $formResponse->assertOk();
        $formResponse->assertSee('Applications closed');

        $response = $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response->assertSessionHasErrors('applicant_email');
        $this->assertDatabaseCount('event_applications', 0);
    }

    public function test_duplicate_submitted_application_for_same_event_and_email_is_blocked(): void
    {
        $event = Event::factory()->create();

        $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response = $this->post(route('public.events.apply.store', $event->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'PAT@example.com',
        ]);

        $response->assertSessionHasErrors('applicant_email');
        $this->assertDatabaseCount('event_applications', 1);
    }

    public function test_same_email_may_apply_to_a_different_event(): void
    {
        $firstEvent = Event::factory()->create();
        $secondEvent = Event::factory()->create();

        $this->post(route('public.events.apply.store', $firstEvent->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response = $this->post(route('public.events.apply.store', $secondEvent->applyRouteParameters()), [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.com',
        ]);

        $response->assertRedirect(route('public.events.apply.submitted', $secondEvent->applyRouteParameters()));
        $this->assertDatabaseCount('event_applications', 2);
    }

    public function test_authenticated_user_can_also_submit(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('public.events.apply.store', $event->applyRouteParameters()), [
                'applicant_legal_name' => 'Vera Staff',
                'applicant_email' => 'vera@example.com',
            ]);

        $response->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));
        $this->assertDatabaseCount('event_applications', 1);
    }

    public function test_event_slug_under_wrong_organization_returns_not_found(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);
        $otherOrganization = Organization::factory()->create(['slug' => 'other-org']);
        Event::factory()->for($organization)->create(['slug' => 'idaho-decompression-2026']);

        $this->get('/other-org/idaho-decompression-2026/apply')->assertNotFound();
        $this->get('/idaho-burners/idaho-decompression-2026/apply')->assertOk();
    }
}
