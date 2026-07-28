<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\RootPackageLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Platform\Dashboard;
use Tests\TestCase;

/**
 * The God Mode console's visual identity (M15C.1 through M15C.7; GOD-029
 * through GOD-035, GOD-038).
 *
 * Milestone 15B made the console's content about Meridian. These tests cover
 * its appearance: that the chrome resolves from the shared Meridian tokens
 * rather than framework defaults, that the Meridian mark and favicon are
 * served, that the footer states Meridian's own license, copyright range, and
 * build version with no framework equivalent left anywhere, that the
 * authentication and node setup surfaces carry the same identity, and that an
 * organization branding profile cannot reach any of it.
 */
class ConsoleVisualIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_BRIDGE = 'public/css/meridian-console.css';

    private const SURFACE_STYLES = 'public/css/meridian-surface.css';

    /* --------------------------------------------------------------------
     * M15C.1 Token bridge
     * ----------------------------------------------------------------- */

    public function test_the_console_loads_the_shared_tokens_and_then_the_token_bridge(): void
    {
        // Order is the mechanism: the framework links dashboard resource
        // stylesheets after its own, and the bridge only wins because it is
        // last (GOD-037).
        $stylesheets = config('platform.resource.stylesheets');

        $this->assertSame(
            ['/css/meridian-tokens.css', '/css/meridian-console.css'],
            $stylesheets,
        );

        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        $tokensAt = strpos($body, '/css/meridian-tokens.css');
        $bridgeAt = strpos($body, '/css/meridian-console.css');

        $this->assertNotFalse($tokensAt);
        $this->assertNotFalse($bridgeAt);
        $this->assertLessThan($bridgeAt, $tokensAt);
    }

    public function test_console_surface_foreground_border_focus_and_action_colors_resolve_from_the_shared_tokens(): void
    {
        $css = $this->bridge();

        // GOD-034 names five color roles. Each is asserted as a mapping from
        // the framework's own custom property to a Meridian token, because the
        // requirement is about where the value comes from, not what it is.
        $mappings = [
            '--bs-body-bg: var(--m-surface-app);',
            '--bs-secondary-bg: var(--m-surface-base);',
            '--bs-body-color: var(--m-text-primary);',
            '--bs-secondary-color: var(--m-text-muted);',
            '--bs-border-color: var(--m-console-border);',
            '--bs-primary: var(--m-action-primary-bg);',
            '--bs-danger: var(--m-status-danger);',
        ];

        foreach ($mappings as $mapping) {
            $this->assertStringContainsString($mapping, $css);
        }

        $this->assertStringContainsString('outline: 2px solid var(--m-focus-ring);', $css);
    }

    public function test_the_token_bridge_never_restates_a_color(): void
    {
        // A hex value in the bridge would be a Meridian color the token file
        // does not know about, and the console would drift the first time a
        // token changed. Hex is allowed only in comments.
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $this->bridge());

        $this->assertSame(
            [],
            $this->hexColorsIn((string) $withoutComments),
            'The console token bridge must resolve every color from --m-* tokens.',
        );

        $this->assertSame([], $this->hexColorsIn(
            (string) preg_replace('#/\*.*?\*/#s', '', $this->surfaceStyles()),
        ));
    }

    /* --------------------------------------------------------------------
     * M15C.2 Typography and spacing
     * ----------------------------------------------------------------- */

    public function test_console_typography_and_spacing_resolve_from_the_shared_scales(): void
    {
        $css = $this->bridge();

        $this->assertStringContainsString('--bs-body-font-family: var(--m-font-body);', $css);
        $this->assertStringContainsString('--bs-body-font-size: var(--m-text-sm);', $css);
        $this->assertStringContainsString('font-family: var(--m-font-heading);', $css);

        // Chrome, tables, forms, and screen layout each take the spacing scale.
        foreach ([
            '.workspace {',
            '.table thead tr th,',
            '.form-control,',
            '.btn {',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        $this->assertGreaterThanOrEqual(
            10,
            substr_count($css, 'var(--m-space-'),
            'Console spacing should come from the shared spacing scale.',
        );
    }

    /* --------------------------------------------------------------------
     * M15C.3 Console logo, M15C.4 favicon
     * ----------------------------------------------------------------- */

    public function test_the_console_navigation_renders_the_meridian_logo(): void
    {
        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('meridian-brand__mark', $body);
        $this->assertStringContainsString('/img/brand/meridian-mark.png', $body);
        $this->assertStringContainsString('meridian-brand__product', $body);

        // The framework's own brand partial is gone, not merely hidden.
        $this->assertStringNotContainsString('platform::header', $body);
    }

    public function test_the_console_mark_is_the_same_asset_the_product_shell_renders(): void
    {
        // The console serves its own copy because it must render without the
        // client build present. Two copies of a logo is how a product ends up
        // with two logos, so they are held byte-identical.
        $product = base_path('../client/public/assets/brand/meridian-mark.png');

        $this->assertFileExists($product);
        $this->assertSame(
            (string) file_get_contents($product),
            (string) file_get_contents(public_path('img/brand/meridian-mark.png')),
            'The console mark is stale. Run: corepack pnpm run brand:sync',
        );
    }

    public function test_the_compact_mark_is_what_the_collapsed_navigation_state_renders(): void
    {
        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        // Both navigation states render the same markup; the collapsed state
        // is a breakpoint rule, because the framework collapses its sidebar by
        // breakpoint and exposes no class for the collapsed brand.
        $this->assertStringContainsString('meridian-brand__name', $body);

        $css = $this->bridge();
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 991\.98px\) \{\s*\.aside \.meridian-brand__name \{/',
            $css,
        );

        // The mark survives the collapse, and the product name is hidden
        // visually rather than removed, so the brand link keeps its name.
        $collapsed = $this->ruleBlock($css, '@media (max-width: 991.98px)');
        $this->assertStringContainsString('.aside .meridian-brand__mark', $collapsed);
        $this->assertStringNotContainsString('display: none', $collapsed);
    }

    public function test_the_meridian_favicon_is_served_across_console_authentication_and_setup(): void
    {
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertFileExists(public_path('img/brand/meridian-mark.png'));

        // Guest surfaces first: `actingAs` persists for the rest of the test,
        // and the framework redirects an authenticated visitor away from its
        // own sign-in page.
        $surfaces = [
            $this->get('/admin/login'),
            $this->get(route('login')),
            $this->get(route('setup.show')),
            $this->actingAs($this->godModeUser())->get(route('platform.main')),
        ];

        foreach ($surfaces as $response) {
            $response->assertOk();

            $body = (string) $response->getContent();

            $this->assertStringContainsString('favicon.ico', $body);
            $this->assertStringNotContainsString('vendor/orchid/favicon.svg', $body);
        }
    }

    /* --------------------------------------------------------------------
     * M15C.5 Footer replacement
     * ----------------------------------------------------------------- */

    public function test_the_console_footer_states_the_license_the_repository_is_published_under(): void
    {
        $repositoryLicense = RootPackageLicense::resolve(base_path('../..'));

        $this->assertSame('AGPL-3.0-or-later', $repositoryLicense);
        $this->assertSame($repositoryLicense, config('meridian.license'));

        // GOD-033 is about agreement with the repository, so the LICENSE file
        // is checked too: a package manifest can be edited without the license
        // actually changing.
        $this->assertStringContainsString(
            'GNU AFFERO GENERAL PUBLIC LICENSE',
            (string) file_get_contents(base_path('../../LICENSE')),
        );

        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Meridian is published under the AGPL-3.0-or-later license.',
            $body,
        );
    }

    public function test_the_console_footer_states_a_2026_to_present_copyright_range_and_the_meridian_version(): void
    {
        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('2026', $body);
        $this->assertStringContainsString(date('Y'), $body);
        $this->assertStringContainsString(
            'Meridian version: '.config('meridian.version'),
            $body,
        );
    }

    public function test_no_framework_license_copyright_range_or_version_appears_in_the_console(): void
    {
        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        // GOD-032: the framework's footer is replaced, not supplemented.
        $this->assertStringNotContainsString('MIT license', $body);
        $this->assertStringNotContainsString('2016 - ', $body);
        $this->assertStringNotContainsString(Dashboard::version(), $body);
        $this->assertStringNotContainsString('orchid.software', $body);
        $this->assertStringNotContainsString('opencollective.com/orchid', $body);
    }

    public function test_the_footer_is_replaced_on_the_framework_authentication_surface_too(): void
    {
        // The framework renders a longer, differently-worded footer for guests,
        // which is where its project links and its "Crafted with" credit live.
        $body = (string) $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('Meridian is published under the', $body);
        $this->assertStringNotContainsString('MIT license', $body);
        $this->assertStringNotContainsString('Alexandr Chernyaev', $body);
        $this->assertStringNotContainsString('orchid.software', $body);
    }

    /* --------------------------------------------------------------------
     * M15C.6 Auth and setup surfaces
     * ----------------------------------------------------------------- */

    public function test_the_login_surface_carries_the_console_visual_identity(): void
    {
        $this->assertCarriesTheMeridianIdentity(
            (string) $this->get(route('login'))->assertOk()->getContent(),
        );
    }

    public function test_the_magic_link_surface_carries_the_console_visual_identity(): void
    {
        $body = (string) $this->withSession(['magic_link_email' => 'volunteer@example.test'])
            ->get(route('auth.magic-link.sent'))
            ->assertOk()
            ->getContent();

        $this->assertCarriesTheMeridianIdentity($body);
    }

    public function test_the_logout_surface_carries_the_console_visual_identity(): void
    {
        $this->assertCarriesTheMeridianIdentity(
            (string) $this->actingAs(User::factory()->create())
                ->get(route('logout'))
                ->assertOk()
                ->getContent(),
        );
    }

    public function test_the_node_first_run_setup_surface_carries_the_console_visual_identity(): void
    {
        $this->assertCarriesTheMeridianIdentity(
            (string) $this->get(route('setup.show'))->assertOk()->getContent(),
        );
    }

    public function test_the_node_setup_surface_no_longer_carries_its_own_private_styling(): void
    {
        // Setup was the one standalone surface that had been styled, with its
        // own inline copy of the token mapping. Sharing the surface stylesheet
        // is what keeps these pages from drifting into separate identities.
        $view = (string) file_get_contents(resource_path('views/setup/node.blade.php'));

        $this->assertStringNotContainsString('<style>', $view);
        $this->assertStringContainsString("@include('meridian.surface-head')", $view);
    }

    /* --------------------------------------------------------------------
     * M15C.7 Meridian palette isolation
     * ----------------------------------------------------------------- */

    public function test_an_active_organization_branding_profile_does_not_change_console_appearance(): void
    {
        $user = $this->godModeUser();

        $unbranded = (string) $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        Organization::factory()->branded('Deep Harbor Collective')->create();

        $branded = (string) $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            $this->stylingOf($unbranded),
            $this->stylingOf($branded),
            'A branded organization must not change how the console looks (GOD-035, BRAND-003).',
        );
    }

    public function test_the_console_never_resolves_an_organization_branding_profile(): void
    {
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $body = (string) $this->actingAs($this->godModeUser())
            ->get(route('platform.main'))
            ->assertOk()
            ->getContent();

        // Two independent guarantees: the branding stylesheet is not linked,
        // and the attribute its token layer is scoped to is not present, so
        // loading it by accident would still be inert.
        $this->assertStringNotContainsString(
            route('branding.stylesheet', ['organization' => $organization->id]),
            $body,
        );
        $this->assertStringNotContainsString('branding/'.$organization->id.'/tokens.css', $body);
        $this->assertStringNotContainsString('data-organization-branding', $body);
        $this->assertStringNotContainsString('Deep Harbor Collective', $body);
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function assertCarriesTheMeridianIdentity(string $body): void
    {
        $this->assertStringContainsString('/css/meridian-tokens.css', $body);
        $this->assertStringContainsString('/css/meridian-surface.css', $body);
        $this->assertStringContainsString('favicon.ico', $body);
        $this->assertStringContainsString('meridian-brand__mark', $body);
        $this->assertStringContainsString('/img/brand/meridian-mark.png', $body);
        $this->assertStringContainsString('Meridian is published under the', $body);
        $this->assertStringContainsString('Meridian version: '.config('meridian.version'), $body);
    }

    private function bridge(): string
    {
        return (string) file_get_contents(base_path(self::TOKEN_BRIDGE));
    }

    private function surfaceStyles(): string
    {
        return (string) file_get_contents(base_path(self::SURFACE_STYLES));
    }

    /**
     * @return list<string>
     */
    private function hexColorsIn(string $css): array
    {
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $matches);

        return $matches[0];
    }

    private function ruleBlock(string $css, string $opener): string
    {
        $start = strpos($css, $opener);
        $this->assertNotFalse($start, "Expected {$opener} in the console stylesheet.");

        $depth = 0;
        for ($i = $start; $i < strlen($css); $i++) {
            if ($css[$i] === '{') {
                $depth++;
            }

            if ($css[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($css, $start, $i - $start + 1);
                }
            }
        }

        return substr($css, $start);
    }

    /**
     * Everything about a rendered console page that decides how it looks: the
     * stylesheets it links and the inline style it carries.
     *
     * @return array{stylesheets: list<string>, inline: list<string>}
     */
    private function stylingOf(string $body): array
    {
        preg_match_all('/<link[^>]+rel="stylesheet"[^>]*>/i', $body, $links);
        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $body, $styles);

        return [
            'stylesheets' => $links[0],
            'inline' => $styles[1],
        ];
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.documentation' => true,
                'platform.changelog' => true,
            ],
        ]);
    }
}
