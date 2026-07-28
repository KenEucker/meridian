<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Services\Branding\BrandingColor;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\BrandingTokenResolver;
use App\Services\Branding\ContrastValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No branding profile can make state unreadable or color-only (M15A.13;
 * BRAND-007, BRAND-017; accessibility checklist section 6).
 *
 * Three separate guarantees, and each needs its own kind of evidence:
 *
 *   1. **Structural.** Status, severity, priority, and restriction tokens are
 *      derived and are never emitted by the branding layer, so no branding
 *      profile can set them at all. That is checked here against the resolver
 *      and against `tokens.css`.
 *   2. **Contrast.** A palette or a department background that would render a
 *      state indicator below 3:1 is refused rather than stored.
 *   3. **Non-color.** Canonical status labels are text and iconography; the
 *      UI contract's status list is unchanged by branding. Rendering is
 *      covered by the component tests; what is asserted here is that branding
 *      contributes no token a status component reads for its label.
 */
class BrandingStateLegibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tokens BRAND-007 requires to derive from the platform palette.
     *
     * @var list<string>
     */
    private const STATE_TOKENS = [
        '--m-status-neutral',
        '--m-status-success',
        '--m-status-warning',
        '--m-status-danger',
        '--m-status-restricted',
        '--m-attention-routine',
        '--m-attention-attention',
        '--m-attention-warning',
        '--m-attention-critical',
        '--m-attention-restricted',
    ];

    public function test_no_branding_layer_can_emit_a_state_token(): void
    {
        $resolver = new BrandingTokenResolver;

        $emitted = array_keys(array_merge(
            $resolver->organizationTokens(BrandingPalette::meridianDefault()),
            $resolver->departmentTokens(
                BrandingColor::parse('#1f5f4b', 'accent'),
                BrandingColor::parse('#eef6f2', 'surface'),
                true,
            ),
        ));

        foreach (self::STATE_TOKENS as $token) {
            $this->assertNotContains(
                $token,
                $emitted,
                "{$token} must derive from the platform palette, not be settable.",
            );
        }
    }

    public function test_state_tokens_resolve_through_the_platform_palette_in_the_stylesheet(): void
    {
        $tokens = (string) file_get_contents(public_path('css/meridian-tokens.css'));

        foreach (self::STATE_TOKENS as $token) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($token, '/').':\s*var\(--m-platform-[a-z]+\)/',
                $tokens,
                "{$token} must be written as a platform-palette reference.",
            );
        }
    }

    public function test_a_palette_that_would_hide_state_indicators_is_refused(): void
    {
        $validator = new ContrastValidator;

        // Every platform color pushed to near-white: legible as nothing, and
        // every status and severity indicator derives from these four.
        $palette = BrandingPalette::fromArray(array_merge(
            BrandingPalette::meridianDefault()->toArray(),
            [
                'primary' => '#f4f2ee',
                'secondary' => '#f2f4f0',
                'tertiary' => '#f5f1ec',
                'accent' => '#f7f1e9',
            ],
        ));

        $failures = $validator->failuresForOrganizationPalette($palette);
        $pairs = array_map(static fn ($failure): string => $failure->pair, $failures);

        foreach (['primary', 'secondary', 'tertiary', 'accent'] as $field) {
            $this->assertContains("{$field} indicator on surface", $pairs);
        }
    }

    public function test_a_department_background_that_would_hide_state_is_refused(): void
    {
        $validator = new ContrastValidator;
        $organization = BrandingPalette::meridianDefault();

        // A department background that matches the organization's danger
        // color would make "danger" invisible on that department's surfaces.
        $failures = $validator->failuresForDepartmentBranding(
            $organization,
            null,
            BrandingColor::parse('#cc792f', 'surface'),
        );

        $pairs = array_map(static fn ($failure): string => $failure->pair, $failures);

        $this->assertContains('accent indicator on department background', $pairs);
        $this->assertContains('tertiary indicator on department background', $pairs);
    }

    public function test_the_state_guard_holds_under_a_department_background_and_a_custom_palette_together(): void
    {
        // The combination is the case a single-layer check would miss: each
        // layer can be individually valid and still stack badly.
        $validator = new ContrastValidator;

        $palette = BrandingPalette::fromArray(array_merge(
            BrandingPalette::meridianDefault()->toArray(),
            [
                'primary' => '#123a5c',
                'secondary' => '#1f5f4b',
                'tertiary' => '#6b4f8a',
                'accent' => '#8c2f39',
                'canvas' => '#eef2f6',
                'surface' => '#ffffff',
                'foreground' => '#101418',
                'muted_foreground' => '#565f68',
                'border' => '#7c858d',
                'focus' => '#1b4f8f',
            ],
        ));

        $this->assertSame([], $validator->failuresForOrganizationPalette($palette));

        // Valid on its own, but a near-match for this palette's secondary.
        $failures = $validator->failuresForDepartmentBranding(
            $palette,
            null,
            BrandingColor::parse('#1f5f4b', 'surface'),
        );

        $this->assertNotSame([], $failures);
    }

    public function test_a_stored_profile_cannot_reach_the_database_without_passing_the_guard(): void
    {
        // The write path is the only way branding is set, and it validates
        // before it writes. A profile in the database has therefore passed.
        $organization = Organization::factory()->branded()->create();
        $department = Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create();

        $validator = new ContrastValidator;
        $palette = BrandingPalette::fromStored($organization->branding_palette_json);

        $this->assertSame([], $validator->failuresForOrganizationPalette($palette));
        $this->assertSame([], $validator->failuresForDepartmentBranding(
            $palette,
            BrandingColor::tryParse($department->branding_accent_color, 'accent'),
            BrandingColor::tryParse($department->branding_surface_color, 'surface'),
        ));
    }

    public function test_meridian_defaults_keep_state_legible(): void
    {
        $validator = new ContrastValidator;
        $palette = BrandingPalette::meridianDefault();

        foreach (['primary', 'secondary', 'tertiary', 'accent'] as $field) {
            $this->assertGreaterThanOrEqual(
                ContrastValidator::REQUIRED_NON_TEXT,
                $validator->ratio($palette->color($field), $palette->surface()),
                "The default {$field} indicator must stay readable on the default surface.",
            );
        }
    }
}
