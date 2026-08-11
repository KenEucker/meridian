<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branding profile resolution by request host (M19.9; ORG-023, BRAND-003;
 * technical spec 8.7).
 *
 * On an organization subdomain the host-addressed branding pair —
 * `/branding/tokens.css` and `/branding/manifest.json`, no organization
 * segment — answers with the host organization's profile, the same profile the
 * organization-addressed routes serve. At the deployment root the same pair
 * answers with Meridian's own identity, because the root carries no
 * organization and the marketing surface never adopts one (BRAND-003).
 */
class OrganizationSubdomainBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    public function test_the_subdomain_resolves_the_host_organizations_branding_profile(): void
    {
        $organization = Organization::factory()
            ->branded('Northwood Collective')
            ->create(['slug' => 'northwood']);

        $manifest = $this->getJson('http://northwood.localhost/branding/manifest.json');

        $manifest->assertOk();
        $manifest->assertJsonPath('organization_id', (string) $organization->id);
        $manifest->assertJsonPath('display_name', 'Northwood Collective');
        $manifest->assertJsonPath('has_custom_palette', true);
    }

    public function test_the_subdomain_stylesheet_matches_the_organization_addressed_stylesheet(): void
    {
        $organization = Organization::factory()
            ->branded('Northwood Collective')
            ->create(['slug' => 'northwood']);

        $byHost = $this->get('http://northwood.localhost/branding/tokens.css');
        $byOrganization = $this->get(route('branding.stylesheet', ['organization' => $organization->id]));

        $byHost->assertOk();
        $byOrganization->assertOk();

        // ORG-023: the subdomain resolves the same branding profile the
        // organization-addressed form resolves — the stylesheets are the same
        // bytes.
        $this->assertSame($byOrganization->content(), $byHost->content());
        $this->assertStringContainsString('Northwood Collective', $byHost->content());
    }

    public function test_the_deployment_root_resolves_meridians_own_identity(): void
    {
        Organization::factory()
            ->branded('Northwood Collective')
            ->create(['slug' => 'northwood']);

        $manifest = $this->getJson('http://localhost/branding/manifest.json');

        $manifest->assertOk();
        $manifest->assertJsonPath('organization_id', null);
        $manifest->assertJsonPath('has_custom_palette', false);

        $stylesheet = $this->get('http://localhost/branding/tokens.css');

        $stylesheet->assertOk();
        $this->assertStringNotContainsString('Northwood Collective', $stylesheet->content());
    }
}
