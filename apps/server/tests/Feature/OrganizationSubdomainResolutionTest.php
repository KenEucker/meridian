<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organization subdomain resolution (M19.8; ORG-022 through ORG-025;
 * PUBLIC-001; technical spec 8.7).
 *
 * The platform host is derived from the configured application URL, so these
 * tests pin it to `localhost` and drive requests by `Host` header — the
 * development shape the spec names (`northwood.localhost`).
 */
class OrganizationSubdomainResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    private function organizationWithEvent(string $slug = 'northwood', string $eventSlug = 'emberfall-2026'): Event
    {
        $organization = Organization::factory()->create(['slug' => $slug]);

        return Event::factory()->for($organization)->create([
            'slug' => $eventSlug,
            'name' => 'Emberfall 2026',
        ]);
    }

    public function test_an_organization_subdomain_serves_the_apply_form_with_the_slug_segment_omitted(): void
    {
        $event = $this->organizationWithEvent();

        $response = $this->get('http://northwood.localhost/emberfall-2026/apply');

        $response->assertOk();
        $response->assertSee('Emberfall 2026');
        $response->assertSee('Submit application');
    }

    public function test_a_subdomain_submission_creates_the_same_application_the_path_form_creates(): void
    {
        $event = $this->organizationWithEvent();

        $response = $this->post('http://northwood.localhost/emberfall-2026/apply', [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.org',
        ]);

        $response->assertRedirect();

        $application = EventApplication::query()->where('event_id', $event->id)->first();

        $this->assertNotNull($application);
        $this->assertSame('pat@example.org', $application->applicant_email);
    }

    public function test_the_path_form_keeps_working_unchanged(): void
    {
        $event = $this->organizationWithEvent();

        $this->assertSame(
            '/northwood/emberfall-2026/apply',
            parse_url(route('public.events.apply', $event->applyRouteParameters()), PHP_URL_PATH),
        );

        $this->get('/northwood/emberfall-2026/apply')
            ->assertOk()
            ->assertSee('Emberfall 2026');

        $this->post('/northwood/emberfall-2026/apply', [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.org',
        ])->assertRedirect(route('public.events.apply.submitted', $event->applyRouteParameters()));
    }

    public function test_an_unknown_subdomain_is_not_found_for_every_path(): void
    {
        $this->organizationWithEvent();

        $this->get('http://nowhere.localhost/')->assertNotFound();
        $this->get('http://nowhere.localhost/login')->assertNotFound();
        $this->get('http://nowhere.localhost/emberfall-2026/apply')->assertNotFound();
        $this->getJson('http://nowhere.localhost/api/public/organizations/northwood')->assertNotFound();
    }

    public function test_an_archived_organizations_slug_is_not_an_active_subdomain(): void
    {
        $event = $this->organizationWithEvent();
        $event->organization->update(['archived_at' => now()]);

        $this->get('http://northwood.localhost/emberfall-2026/apply')->assertNotFound();
        $this->get('http://northwood.localhost/')->assertNotFound();
    }

    public function test_the_marketing_surface_does_not_render_on_an_organization_subdomain(): void
    {
        $this->organizationWithEvent();

        // At the deployment root the surface serves; on the organization's own
        // subdomain the same node answers not found (ORG-024, PUBLIC-001).
        $this->getJson('/api/public/marketing-surface')->assertOk();
        $this->getJson('http://northwood.localhost/api/public/marketing-surface')->assertNotFound();

        $this->postJson('http://northwood.localhost/api/public/organization-inquiries', [
            'organization_name' => 'Someone',
            'contact_name' => 'Some One',
            'contact_email' => 'someone@example.org',
            'description' => 'A perfectly reasonable inquiry.',
        ])->assertNotFound();
    }

    public function test_a_subdomain_never_serves_another_organizations_content(): void
    {
        $this->organizationWithEvent();
        $otherEvent = $this->organizationWithEvent('sagewood', 'lanternfest-2026');

        // Sagewood's path form and public reads are not found on Northwood's
        // subdomain (ORG-024)...
        $this->get('http://northwood.localhost/sagewood/lanternfest-2026/apply')->assertNotFound();
        $this->getJson('http://northwood.localhost/api/public/organizations/sagewood')->assertNotFound();

        // ...and the subdomain apply route resolves events scoped to the host
        // organization, so Sagewood's event slug does not resolve there.
        $this->get('http://northwood.localhost/lanternfest-2026/apply')->assertNotFound();

        // ...while Northwood's own reads answer there, and Sagewood's answer on
        // Sagewood's.
        $this->getJson('http://northwood.localhost/api/public/organizations/northwood')->assertOk();
        $this->getJson('http://sagewood.localhost/api/public/organizations/sagewood')->assertOk();
    }

    public function test_the_signed_in_client_and_api_serve_on_an_organization_subdomain(): void
    {
        $this->organizationWithEvent();

        // The organization lives entirely on its subdomain (technical spec
        // 8.7): the client shell and the API endpoints it calls resolve there.
        $this->get('http://northwood.localhost/')->assertOk();
        $this->get('http://northwood.localhost/login')->assertOk();
        $this->getJson('http://northwood.localhost/api/public/organizations/northwood')->assertOk();
    }

    public function test_hosts_outside_the_platform_domain_fall_through_to_path_resolution(): void
    {
        $this->organizationWithEvent();

        // A host under some other domain is not organization addressing, even
        // when its first label matches a slug: the subdomain form of the apply
        // path does not exist there, and the path form still resolves.
        $this->get('http://northwood.example.com/emberfall-2026/apply')->assertNotFound();
        $this->get('http://northwood.example.com/northwood/emberfall-2026/apply')->assertOk();

        // A multi-label name under the platform host is not organization
        // addressing either ("any other host falls through to path
        // resolution", technical spec 8.7).
        $this->get('http://a.b.localhost/northwood/emberfall-2026/apply')->assertOk();
    }
}
