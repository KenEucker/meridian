<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\BrandingTokenResolver;
use App\Services\Organizations\OrganizationHostContext;
use Illuminate\Http\Response;

/**
 * Serves an organization's resolved branding as a stylesheet (M15A.4;
 * BRAND-006, BRAND-007, BRAND-022).
 *
 * Loaded after `meridian-tokens.css`, which holds Meridian's defaults and every
 * derived token. This route emits only the settable values and the computed
 * action label colors, scoped to `:root[data-organization-branding="applied"]`,
 * so a surface that has not declared itself branded keeps Meridian's identity
 * even with the file loaded (BRAND-003).
 *
 * The response is cacheable and carries an ETag over the resolved values rather
 * than a timestamp, so a device holding a cached copy revalidates cheaply and
 * keeps rendering the organization's palette while offline (BRAND-022).
 */
final class BrandingStylesheetController extends Controller
{
    /**
     * The stylesheet at the host's own address (M19.9; ORG-023, BRAND-003;
     * technical spec 8.7): on an organization subdomain it is that
     * organization's branding — the same profile the organization-addressed
     * route serves — with no organization segment in the path; at the
     * deployment root it is Meridian's own identity, because the root carries
     * no organization and the marketing surface never adopts one (BRAND-003).
     */
    public function showForHost(
        OrganizationHostContext $context,
        BrandingResolver $resolver,
        BrandingTokenResolver $tokens,
    ): Response {
        return $this->show((string) ($context->organization()?->getKey() ?? ''), $resolver, $tokens);
    }

    public function show(string $organization, BrandingResolver $resolver, BrandingTokenResolver $tokens): Response
    {
        $profile = $resolver->forOrganizationId($organization);

        // An organization that set a name or a logo but never chose colors
        // has not asked for a palette, and emitting Meridian's defaults as
        // though it had would override the dark theme.
        $css = $this->header($profile)
            .($profile->hasCustomPalette ? $tokens->organizationStylesheet($profile->palette) : '')
            ."\n"
            .$tokens->departmentStylesheet(
                $this->departmentBranding($profile),
                $profile->departmentOverridesEnabled,
            );

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60, must-revalidate',
            'ETag' => '"'.hash('sha256', $css).'"',
        ]);
    }

    /**
     * @return array<string, array{accent: ?\App\Services\Branding\BrandingColor, surface: ?\App\Services\Branding\BrandingColor}>
     */
    private function departmentBranding(BrandingProfile $profile): array
    {
        $departments = [];

        foreach ($profile->departments as $departmentId => $branding) {
            $departments[$departmentId] = [
                'accent' => $branding->accent,
                'surface' => $branding->surfaceBackground,
            ];
        }

        return $departments;
    }

    private function header(BrandingProfile $profile): string
    {
        return sprintf(
            "/* Meridian branding layer for %s. Generated; edit the branding profile instead. */\n",
            $profile->displayName,
        );
    }
}
