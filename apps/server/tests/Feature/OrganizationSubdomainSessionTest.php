<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Session cookie scope across organization subdomains (M19.9; ORG-024;
 * technical spec 8.7).
 *
 * The isolation mechanism is the cookie's domain attribute: a host-only cookie
 * — one issued with no `Domain` attribute — is presented by browsers to the
 * exact host that set it and to no other, so a session issued on one
 * organization subdomain is never presented to another. These tests pin that
 * attribute, because it is the whole boundary: the spec requires the scope be
 * set deliberately, and the deliberate setting is the default host-only
 * cookie.
 */
class OrganizationSubdomainSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    private function sessionCookie(TestResponse $response): ?Cookie
    {
        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_the_session_cookie_domain_is_host_only_by_default(): void
    {
        $this->assertNull(
            config('session.domain'),
            'SESSION_DOMAIN must stay unset by default: a parent-domain session cookie '
            .'would be presented across organization subdomains (technical spec 8.7).',
        );
    }

    public function test_a_session_issued_on_one_organization_subdomain_is_not_presented_to_another(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood']);
        Event::factory()->for($organization)->create(['slug' => 'emberfall-2026']);
        Organization::factory()->create(['slug' => 'sagewood']);

        $response = $this->get('http://northwood.localhost/emberfall-2026/apply');

        $response->assertOk();

        $cookie = $this->sessionCookie($response);

        $this->assertNotNull($cookie, 'The apply page should have started a session.');

        // No Domain attribute makes the cookie host-only: the browser presents
        // it to northwood.localhost and never to sagewood.localhost or to the
        // deployment root. That attribute is the isolation ORG-024 requires,
        // so it is asserted here rather than assumed.
        $this->assertNull(
            $cookie->getDomain(),
            'The session cookie must carry no Domain attribute on an organization subdomain.',
        );
    }

    public function test_the_session_round_trip_works_within_one_subdomain(): void
    {
        $organization = Organization::factory()->create(['slug' => 'northwood']);
        Event::factory()->for($organization)->create([
            'slug' => 'emberfall-2026',
            'name' => 'Emberfall 2026',
        ]);

        // Submit on the subdomain, then read the submitted page there: the
        // flash session written by the store request is what renders it, so a
        // working page proves the cookie round-trips on its own host.
        $store = $this->post('http://northwood.localhost/emberfall-2026/apply', [
            'applicant_legal_name' => 'Pat Prospective',
            'applicant_email' => 'pat@example.org',
        ]);

        $store->assertRedirect();

        $this->get($store->headers->get('Location'))
            ->assertOk()
            ->assertSee('Application submitted');
    }
}
