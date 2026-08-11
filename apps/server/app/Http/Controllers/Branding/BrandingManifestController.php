<?php

namespace App\Http\Controllers\Branding;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\Branding\BrandingProfile;
use App\Services\Branding\BrandingResolver;
use App\Services\Organizations\OrganizationHostContext;
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
    /**
     * The manifest at the host's own address (M19.9; ORG-023, BRAND-003;
     * technical spec 8.7): the host organization's profile on its subdomain,
     * Meridian's at the deployment root, resolved the same way the stylesheet
     * beside it resolves.
     */
    public function showForHost(OrganizationHostContext $context, BrandingResolver $resolver): JsonResponse
    {
        return $this->show((string) ($context->organization()?->getKey() ?? ''), $resolver);
    }

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
            // The event this install is locked to, so a cached copy can tell
            // afterwards which mark its chrome is supposed to be carrying
            // (BRAND-028).
            'locked_event_id' => $profile->lockedEvent?->eventId,
            'locked_event_name' => $profile->lockedEvent?->name,
            /*
             * The name that goes with the chrome mark, which is the event's
             * where one is showing (BRAND-029).
             *
             * Alongside `display_name` rather than replacing it: that field is
             * the organization's identity, and it is what a cached copy needs
             * for anything that leaves the product — a generated export or a
             * message naming an event but no organization gives its recipient
             * no accountable party.
             */
            'chrome_display_name' => $profile->chromeIdentityName(),
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

        // The locked event's mark is what the chrome on this install actually
        // renders, so a device warming its cache needs the bytes before it goes
        // offline as much as it needs the organization's (BRAND-022,
        // BRAND-028).
        if ($profile->lockedEvent?->logoAttachmentId !== null) {
            $ids[Attachment::BRANDING_SLOT_EVENT_LOGO] = $profile->lockedEvent->logoAttachmentId;
        }

        foreach ($profile->departments as $branding) {
            if ($branding->logoAttachmentId !== null) {
                $ids[Attachment::BRANDING_SLOT_DEPARTMENT_LOGO.':'.$branding->departmentId]
                    = $branding->logoAttachmentId;
            }
        }

        foreach ($profile->teams as $branding) {
            if ($branding->logoAttachmentId !== null) {
                $ids[Attachment::BRANDING_SLOT_TEAM_LOGO.':'.$branding->teamId]
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
            // Re-pointing a node at a different event changes what its chrome
            // renders without changing a single colour, so the lock is part of
            // what a cached copy has to match.
            $profile->lockedEvent?->eventId,
            $profile->lockedEvent?->name,
            $profile->lockedEvent?->logoAttachmentId,
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
