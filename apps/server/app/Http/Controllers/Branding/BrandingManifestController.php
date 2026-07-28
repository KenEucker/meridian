<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\BrandingResolver;
use Illuminate\Http\JsonResponse;

/**
 * Everything an on-site node or an offline device needs to hold a complete
 * copy of an organization's branding (M15A.12; BRAND-022).
 *
 * Branding is small — ten colors, a display name, and at most one logo per
 * slot — but it is spread across a stylesheet, a JSON profile, and one binary
 * per asset. A device warming its cache before it loses connectivity needs the
 * list, and it needs to be able to tell afterwards whether what it holds is
 * still current. The manifest is that list plus a fingerprint per item.
 *
 * Fingerprints are content hashes, not timestamps. A device that reconnects
 * after a week should re-download a logo because the bytes changed, not
 * because a clock moved; on-site nodes and devices do not share a clock, and
 * `branding_updated_at` moves when an unrelated slot is replaced.
 *
 * Unauthenticated for the same reason the stylesheet and the asset route are:
 * branding is chrome that has to resolve before a session does, and nothing
 * here is operational content.
 */
final class BrandingManifestController extends Controller
{
    public function show(string $organization, BrandingResolver $resolver): JsonResponse
    {
        $profile = $resolver->forOrganizationId($organization);

        return response()->json([
            'organization_id' => $profile->organizationId,
            'display_name' => $profile->identityName(),
            'is_branded' => $profile->isBranded,
            'has_custom_palette' => $profile->hasCustomPalette,
            'department_branding_enabled' => $profile->departmentOverridesEnabled,
            'palette' => $profile->palette->toArray(),
            'palette_fingerprint' => $this->fingerprint($profile),
            'stylesheet_url' => $profile->organizationId !== null
                ? route('branding.stylesheet', ['organization' => $profile->organizationId])
                : null,
            'assets' => $this->assets($profile),
        ]);
    }

    /**
     * @return list<array{slot: string, url: string, mime_type: string, byte_size: int, checksum: string}>
     */
    private function assets(BrandingProfile $profile): array
    {
        $ids = [
            Attachment::BRANDING_SLOT_FULL_LOCKUP => $profile->fullLockupAttachmentId,
            Attachment::BRANDING_SLOT_COMPACT_MARK => $profile->compactMarkAttachmentId,
        ];

        foreach ($profile->departments as $branding) {
            if ($branding->logoAttachmentId !== null) {
                $ids[Attachment::BRANDING_SLOT_DEPARTMENT_LOGO.':'.$branding->departmentId]
                    = $branding->logoAttachmentId;
            }
        }

        $ids = array_filter($ids, static fn (?string $id): bool => $id !== null);

        if ($ids === []) {
            return [];
        }

        $attachments = Attachment::query()
            ->whereIn('id', array_values($ids))
            ->get()
            ->keyBy(fn (Attachment $attachment): string => (string) $attachment->getKey());

        $assets = [];

        foreach ($ids as $slot => $id) {
            $attachment = $attachments->get((string) $id);

            if (! $attachment instanceof Attachment) {
                continue;
            }

            $assets[] = [
                'slot' => $slot,
                'url' => route('branding.asset', ['attachment' => $attachment->getKey()]),
                'mime_type' => (string) $attachment->mime_type,
                'byte_size' => (int) $attachment->byte_size,
                'checksum' => (string) $attachment->checksum,
            ];
        }

        return $assets;
    }

    /**
     * A stable hash over everything a cached palette would need to match.
     */
    private function fingerprint(BrandingProfile $profile): string
    {
        return hash('sha256', json_encode([
            $profile->identityName(),
            $profile->palette->toArray(),
            $profile->departmentOverridesEnabled,
            array_map(
                static fn ($branding): array => [
                    $branding->departmentId,
                    $branding->accent?->hex,
                    $branding->surfaceBackground?->hex,
                    $branding->logoAttachmentId,
                ],
                array_values($profile->departments),
            ),
        ], JSON_THROW_ON_ERROR));
    }
}
