<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Branding reaching on-site nodes and offline devices (M15A.12; BRAND-022).
 *
 * Scope note. The organization and department *rows* reach an on-site node by
 * the same governance replication path as the rest of central-owned data;
 * M15A does not introduce a second one. What is here is what branding needs on
 * top of that: a manifest a node or device can prefetch from, assets that stay
 * valid in a cache, and a palette that renders with no network. The client-side
 * half — cache-first application and offline rendering — is covered by
 * `apps/client/src/branding/brandingProfile.spec.ts`.
 */
class BrandingSyncAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_lists_everything_needed_to_hold_a_complete_copy(): void
    {
        Storage::fake('attachments');

        $actor = User::factory()->create();
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();
        $department = Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create();

        app(BrandingAssetService::class)->put(
            $organization,
            Attachment::BRANDING_SLOT_COMPACT_MARK,
            $this->pngBytes(),
            $actor,
        );

        app(BrandingAssetService::class)->put(
            $department,
            Attachment::BRANDING_SLOT_DEPARTMENT_LOGO,
            $this->pngBytes(),
            $actor,
        );

        $response = $this->getJson(route('branding.manifest', ['organization' => $organization->id]));

        $response->assertOk();
        $response->assertJsonPath('display_name', 'Deep Harbor Collective');
        $response->assertJsonPath('palette.primary', '#123a5c');
        $response->assertJsonPath('department_branding_enabled', true);

        $assets = collect($response->json('assets'));

        $this->assertCount(2, $assets);
        $this->assertNotNull($assets->firstWhere('slot', Attachment::BRANDING_SLOT_COMPACT_MARK));
        $this->assertNotNull(
            $assets->firstWhere('slot', Attachment::BRANDING_SLOT_DEPARTMENT_LOGO.':'.$department->id),
        );

        foreach ($assets as $asset) {
            $this->assertNotSame('', $asset['checksum']);
            $this->assertGreaterThan(0, $asset['byte_size']);
        }
    }

    public function test_the_manifest_fingerprint_changes_only_when_branding_changes(): void
    {
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $first = $this->getJson(route('branding.manifest', ['organization' => $organization->id]))
            ->json('palette_fingerprint');

        // An unrelated write does not invalidate a device's cached copy.
        $organization->forceFill(['calendar_year_start_month' => 4])->save();

        $second = $this->getJson(route('branding.manifest', ['organization' => $organization->id]))
            ->json('palette_fingerprint');

        $this->assertSame($first, $second);

        $organization->forceFill([
            'branding_palette_json' => array_merge(
                $organization->branding_palette_json,
                ['primary' => '#0f3350'],
            ),
        ])->save();

        $third = $this->getJson(route('branding.manifest', ['organization' => $organization->id]))
            ->json('palette_fingerprint');

        $this->assertNotSame($first, $third);
    }

    public function test_a_department_background_disappears_from_the_manifest_when_overrides_are_off(): void
    {
        $organization = Organization::factory()
            ->branded('Deep Harbor Collective')
            ->withDepartmentBrandingDisabled()
            ->create();

        Department::factory()->for($organization)->branded('#1f5f4b', '#eef6f2')->create();

        $response = $this->getJson(route('branding.manifest', ['organization' => $organization->id]));

        $response->assertJsonPath('department_branding_enabled', false);

        // A device syncing this manifest must not cache a background the
        // organization has switched off (BRAND-013).
        $css = $this->get(route('branding.stylesheet', ['organization' => $organization->id]))
            ->getContent();

        $this->assertStringNotContainsString('--m-department-surface', $css);
    }

    public function test_a_branding_asset_is_served_with_a_long_immutable_cache(): void
    {
        Storage::fake('attachments');

        $actor = User::factory()->create();
        $organization = Organization::factory()->create();

        $attachment = app(BrandingAssetService::class)->put(
            $organization,
            Attachment::BRANDING_SLOT_FULL_LOCKUP,
            $this->pngBytes(),
            $actor,
        );

        $response = $this->get(route('branding.asset', ['attachment' => $attachment->getKey()]));

        $response->assertOk();
        // Replacing a logo mints a new attachment id, so the URL changes when
        // the bytes change and a long cache can never serve a stale asset.
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_asset_route_refuses_a_non_branding_attachment(): void
    {
        // The route is unauthenticated, so it must not become a second door
        // onto Field Report photos.
        $photo = Attachment::factory()->create();

        $this->get(route('branding.asset', ['attachment' => $photo->getKey()]))
            ->assertNotFound();
    }

    public function test_branding_resolves_for_an_organization_with_no_profile_so_an_offline_device_still_renders(): void
    {
        $organization = Organization::factory()->create();

        $manifest = $this->getJson(route('branding.manifest', ['organization' => $organization->id]));

        $manifest->assertOk();
        $manifest->assertJsonPath('is_branded', false);
        $manifest->assertJsonPath('display_name', 'Meridian');
        $manifest->assertJsonPath('assets', []);
        $manifest->assertJsonPath('palette.primary', '#475157');
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
