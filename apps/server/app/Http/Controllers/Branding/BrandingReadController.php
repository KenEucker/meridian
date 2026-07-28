<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\BrandingTokenResolver;
use Illuminate\Http\JsonResponse;

/**
 * The branding profile a client applies to its own document (M15A.4, M15A.8,
 * M15A.12; BRAND-002, BRAND-006, BRAND-013, BRAND-022).
 *
 * The client could load the generated stylesheet instead, and for the palette
 * alone that would be enough. It needs this because branding is more than
 * color: the display name goes in the document title and the header, the
 * lettermark is rendered when there is no mark, and all of it has to survive a
 * device going offline. A JSON payload is cacheable in local storage and
 * re-applicable on a cold start with no network; a stylesheet is not.
 *
 * Reading a branding profile is not privileged. Every signed-in user of an
 * organization sees its identity on every screen — that is the point of
 * BRAND-002 — so scoping the read to a role would only mean some staff saw a
 * differently branded product than others.
 */
final class BrandingReadController extends Controller
{
    public function show(
        Organization $organization,
        BrandingResolver $resolver,
        BrandingTokenResolver $tokens,
    ): JsonResponse {
        $profile = $resolver->forOrganization($organization);

        return response()->json($this->payload($profile, $tokens));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(BrandingProfile $profile, BrandingTokenResolver $tokens): array
    {
        $departments = [];

        foreach ($profile->departments as $departmentId => $branding) {
            $departments[] = [
                'department_id' => $departmentId,
                'name' => $branding->name,
                'accent' => $branding->accent?->hex,
                'surface' => $branding->surfaceBackground?->hex,
                'lettermark' => $branding->lettermark(),
                'logo_url' => $branding->logoAttachmentId !== null
                    ? route('branding.asset', ['attachment' => $branding->logoAttachmentId])
                    : null,
            ];
        }

        return [
            'organization_id' => $profile->organizationId,
            'display_name' => $profile->displayName,
            'is_branded' => $profile->isBranded,
            'has_custom_palette' => $profile->hasCustomPalette,
            'document_attribute' => $profile->documentAttribute(),
            'lettermark' => $profile->lettermark(),
            'department_branding_enabled' => $profile->departmentOverridesEnabled,
            'palette' => $profile->palette->toArray(),
            'tokens' => $tokens->organizationTokens($profile->palette),
            'full_lockup_url' => $profile->fullLockupAttachmentId !== null
                ? route('branding.asset', ['attachment' => $profile->fullLockupAttachmentId])
                : null,
            'compact_mark_url' => $profile->compactMarkAttachmentId !== null
                ? route('branding.asset', ['attachment' => $profile->compactMarkAttachmentId])
                : null,
            'departments' => $departments,
        ];
    }
}
