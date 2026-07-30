<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingColor;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\BrandingTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runtime token resolution and client/server parity (M15A.4; BRAND-006,
 * BRAND-007, BRAND-013; UI implementation contract section 10).
 *
 * The parity fixture is `packages/ui-tokens/src/branding.fixture.json`, read by
 * this test and by `packages/ui-tokens/src/branding.spec.ts`. Two resolvers
 * exist because the Laravel/Orchid surfaces are served a stylesheet and the Vue
 * client applies branding to its own document without a round trip; the fixture
 * is what keeps them from drifting.
 */
class BrandingTokenResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $path = base_path('../../packages/ui-tokens/src/branding.fixture.json');

        $this->assertFileExists($path, 'The shared branding token fixture is missing.');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_server_resolution_matches_the_shared_client_fixture(): void
    {
        $resolver = new BrandingTokenResolver;

        foreach ($this->fixture()['validPalettes'] as $name => $entry) {
            $palette = BrandingPalette::fromArray($entry['palette']);

            $this->assertSame(
                $entry['tokens'],
                $resolver->organizationTokens($palette),
                "Server token resolution drifted from the client fixture for {$name}.",
            );
        }
    }

    public function test_meridian_defaults_match_the_fixture(): void
    {
        $this->assertSame(
            $this->fixture()['validPalettes']['meridian-default']['palette'],
            BrandingPalette::meridianDefault()->toArray(),
        );
    }

    public function test_resolution_emits_no_status_severity_or_attention_token(): void
    {
        // BRAND-007: those tokens derive from the platform palette in
        // tokens.css. A branding layer that shipped its own would be an
        // independently settable state color.
        $resolver = new BrandingTokenResolver;

        $tokens = array_merge(
            $resolver->organizationTokens(BrandingPalette::meridianDefault()),
            $resolver->departmentTokens(
                BrandingColor::parse('#1f5f4b', 'accent'),
                BrandingColor::parse('#eef6f2', 'surface'),
                true,
            ),
        );

        foreach (array_keys($tokens) as $token) {
            $this->assertDoesNotMatchRegularExpression('/^--m-(status|attention|chart)-/', $token);
        }
    }

    public function test_department_tokens_are_withheld_when_the_organization_switch_is_off(): void
    {
        $resolver = new BrandingTokenResolver;

        $this->assertSame([], $resolver->departmentTokens(
            BrandingColor::parse('#1f5f4b', 'accent'),
            BrandingColor::parse('#eef6f2', 'surface'),
            false,
        ));
    }

    public function test_a_department_keeps_its_accent_and_loses_its_background_when_overrides_are_off(): void
    {
        // BRAND-013 keeps logo and accent identity and drops only the surface
        // background, so the resolved profile has to show that split.
        $organization = Organization::factory()->withDepartmentBrandingDisabled()->create();
        $department = Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create();

        $profile = app(BrandingResolver::class)->forOrganization($organization->fresh());
        $branding = $profile->department((string) $department->id);

        $this->assertNotNull($branding);
        $this->assertSame('#1f5f4b', $branding->accent?->hex);
        $this->assertNull($branding->surfaceBackground);
    }

    public function test_the_stylesheet_route_emits_the_organization_layer(): void
    {
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $response = $this->get(route('branding.stylesheet', ['organization' => $organization->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');

        $css = $response->getContent();

        $this->assertStringContainsString(':root[data-organization-branding="applied"]', $css);
        $this->assertStringContainsString('--m-platform-primary: #123a5c;', $css);
        $this->assertStringContainsString('--m-surface-app: #eef2f6;', $css);
        $this->assertStringContainsString('--m-action-primary-text:', $css);
    }

    public function test_the_stylesheet_scopes_the_department_background_out_of_dark_mode(): void
    {
        // A department background is a light color validated against the
        // organization's light neutrals. Painting it behind Meridian's dark
        // foreground would produce the combination the validator refuses.
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create();

        $css = $this->get(route('branding.stylesheet', ['organization' => $organization->id]))
            ->getContent();

        $this->assertStringContainsString(
            ':root:not([data-theme="dark"]) [data-department-branding="'.$department->id.'"]',
            $css,
        );
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);

        // The accent is not scoped away: it reads on any surface and is what
        // department identity falls back to in dark mode.
        $this->assertStringContainsString(
            '[data-department-branding="'.$department->id.'"] {'."\n".'  --m-department-accent: #1f5f4b;',
            $css,
        );
    }

    public function test_an_unknown_organization_serves_meridian_defaults_rather_than_failing(): void
    {
        // Branding is chrome. A stylesheet route that 404s takes the whole
        // palette down with it. It emits no palette rules, because Meridian's
        // defaults already live in `tokens.css`; restating them here would
        // override the dark theme at a higher specificity.
        $response = $this->get(route('branding.stylesheet', [
            'organization' => '00000000-0000-4000-8000-000000000000',
        ]));

        $response->assertOk();
        $this->assertStringNotContainsString('--m-platform-primary', $response->getContent());
    }

    public function test_an_organization_with_a_name_but_no_palette_emits_no_palette_rules(): void
    {
        // Identity and palette are separate decisions (BRAND-002, BRAND-006).
        // Emitting Meridian's defaults for an organization that only uploaded
        // a logo made an unstyled organization override the dark theme.
        $organization = Organization::factory()->create([
            'branding_display_name' => 'Local Field Organization',
            'branding_palette_json' => null,
        ]);

        $profile = app(BrandingResolver::class)->forOrganization($organization);

        $this->assertTrue($profile->isBranded);
        $this->assertFalse($profile->hasCustomPalette);

        $css = $this->get(route('branding.stylesheet', ['organization' => $organization->id]))
            ->getContent();

        $this->assertStringNotContainsString('--m-surface-app', $css);
    }

    public function test_the_organization_layer_keeps_light_neutrals_out_of_dark_mode(): void
    {
        // An organization submits one light neutral set (BRAND-006). Painting
        // a light canvas behind Meridian's dark-mode foreground is exactly the
        // combination the validator refuses at submission time.
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $css = $this->get(route('branding.stylesheet', ['organization' => $organization->id]))
            ->getContent();

        $platformRule = substr(
            $css,
            (int) strpos($css, ':root[data-organization-branding="applied"] {'),
            (int) strpos($css, ':root[data-organization-branding="applied"]:not')
                - (int) strpos($css, ':root[data-organization-branding="applied"] {'),
        );

        $this->assertStringContainsString('--m-platform-primary', $platformRule);
        $this->assertStringNotContainsString('--m-surface-app', $platformRule);

        $this->assertStringContainsString(
            ':root[data-organization-branding="applied"]:not([data-theme="dark"])',
            $css,
        );
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
    }

    public function test_an_unbranded_organization_reports_the_default_document_attribute(): void
    {
        $organization = Organization::factory()->create();

        $profile = BrandingProfile::forOrganization($organization);

        $this->assertSame('default', $profile->documentAttribute());
        $this->assertSame('Meridian', BrandingProfile::meridian()->displayName);
        $this->assertSame('applied', BrandingProfile::forOrganization(
            Organization::factory()->branded()->create(),
        )->documentAttribute());
    }

    public function test_the_branding_read_endpoint_returns_palette_tokens_and_departments(): void
    {
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();
        $department = Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create([
            'name' => 'Department of Public Works',
        ]);

        $response = $this->actingAsClient(User::factory()->create())
            ->getJson(route('api.organizations.branding.show', [
                'organization' => $organization->id,
            ]));

        $response->assertOk();
        $response->assertJsonPath('display_name', 'Deep Harbor Collective');
        $response->assertJsonPath('is_branded', true);
        $response->assertJsonPath('document_attribute', 'applied');
        $response->assertJsonPath('department_branding_enabled', true);
        $response->assertJsonPath('palette.primary', '#123a5c');
        $response->assertJsonPath('tokens.--m-platform-primary', '#123a5c');
        $response->assertJsonPath('departments.0.department_id', (string) $department->id);
        $response->assertJsonPath('departments.0.accent', '#1f5f4b');
        $response->assertJsonPath('departments.0.surface', '#eef6f2');
        $response->assertJsonPath('departments.0.lettermark', 'DPW');
    }
}
