<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Branding\BrandingPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Branding on the Orchid administrative screens (M15A.6, M15A.7).
 *
 * Orchid is the repair and inspection console, so it can see and change stored
 * branding without an organizer session. What it does not get is a shortcut
 * around the rules: these assert that the contrast validator, the central-node
 * authority rule, the active-event freeze, and the audit record all still
 * apply when the edit comes from here.
 */
class BrandingOrchidTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'permissions' => [
                'platform.index' => true,
                'platform.systems' => true,
                'platform.organizations' => true,
                'platform.departments' => true,
            ],
        ])->save();

        return $user->fresh();
    }

    public function test_the_organization_screen_shows_the_stored_branding_profile(): void
    {
        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $response = $this->actingAs($this->admin())
            ->get(route('platform.organizations.edit', $organization));

        $response->assertOk();
        $response->assertSee('Branding', false);
        $response->assertSee('Deep Harbor Collective', false);
        $response->assertSee('#123a5c', false);
        $response->assertSee('Full logo lockup', false);
        $response->assertSee('Compact logo mark', false);
    }

    public function test_the_organization_screen_offers_no_branding_before_the_first_save(): void
    {
        // Branding needs an organization to belong to, and a logo needs
        // somewhere to attach.
        $response = $this->actingAs($this->admin())
            ->get(route('platform.organizations.create'));

        $response->assertOk();
        $response->assertDontSee('Full logo lockup', false);
    }

    public function test_an_admin_can_set_the_palette_and_display_name_from_orchid(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);

        $this->actingAs($this->admin())
            ->post(
                route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']),
                $this->organizationPayload($organization, ['display_name' => 'Idaho Burners Collective']),
            )
            ->assertRedirect(route('platform.organizations'));

        $organization->refresh();

        $this->assertSame('Idaho Burners Collective', $organization->branding_display_name);
        $this->assertSame('#123a5c', $organization->branding_palette_json['primary']);
        $this->assertSame(
            AuditEvent::SOURCE_ORCHID,
            AuditEvent::query()->where('action', 'branding.created')->sole()->source_context,
        );
    }

    public function test_orchid_gets_no_exemption_from_the_contrast_validator(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);

        $payload = $this->organizationPayload($organization);
        $payload['branding']['palette']['muted_foreground'] = '#c9cdd1';

        $response = $this->actingAs($this->admin())
            ->from(route('platform.organizations.edit', $organization))
            ->post(route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']), $payload);

        $response->assertSessionHasErrors('branding.palette');

        $errors = session('errors')->get('branding.palette');

        // BRAND-015: the failing pair and both ratios, one message per pair.
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('muted foreground on surface', implode(' ', $errors));
        $this->assertStringContainsString(':1', implode(' ', $errors));

        $this->assertNull($organization->fresh()->branding_palette_json);
    }

    public function test_orchid_gets_no_exemption_from_the_active_event_freeze(): void
    {
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);

        Event::factory()->for($organization)->create([
            'active_event_window_starts_at' => now()->subDay(),
            'active_event_window_ends_at' => now()->addDay(),
        ]);

        $this->actingAs($this->admin())
            ->from(route('platform.organizations.edit', $organization))
            ->post(
                route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']),
                $this->organizationPayload($organization),
            )
            ->assertSessionHasErrors('branding.palette');

        $this->assertNull($organization->fresh()->branding_palette_json);
    }

    public function test_an_admin_can_upload_and_remove_an_organization_logo_from_orchid(): void
    {
        Storage::fake('attachments');

        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);

        $payload = $this->organizationPayload($organization);
        $payload['branding']['full_lockup'] = UploadedFile::fake()->image('lockup.png', 320, 120);

        $this->actingAs($this->admin())
            ->post(route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']), $payload)
            ->assertRedirect(route('platform.organizations'));

        $attachmentId = $organization->fresh()->branding_full_lockup_attachment_id;
        $this->assertNotNull($attachmentId);
        $this->assertDatabaseHas('attachments', [
            'id' => $attachmentId,
            'attachable_type' => Attachment::MORPH_ORGANIZATION,
            'attachable_id' => $organization->id,
        ]);

        $removal = $this->organizationPayload($organization);
        $removal['branding']['remove_full_lockup'] = '1';

        $this->actingAs($this->admin())
            ->post(route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']), $removal)
            ->assertRedirect(route('platform.organizations'));

        $this->assertNull($organization->fresh()->branding_full_lockup_attachment_id);
        // The asset itself survives: attachments are immutable in Alpha 1.
        $this->assertDatabaseHas('attachments', ['id' => $attachmentId]);
    }

    public function test_an_svg_logo_is_refused_from_orchid_too(): void
    {
        Storage::fake('attachments');

        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);

        $payload = $this->organizationPayload($organization);
        $payload['branding']['compact_mark'] = UploadedFile::fake()->createWithContent(
            'mark.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>',
        );

        $this->actingAs($this->admin())
            ->from(route('platform.organizations.edit', $organization))
            ->post(route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']), $payload)
            ->assertSessionHasErrors('branding.compact_mark');

        $this->assertNull($organization->fresh()->branding_compact_mark_attachment_id);
    }

    public function test_the_department_screen_offers_only_the_three_department_values(): void
    {
        $department = Department::factory()->branded('#1f5f4b', '#eef6f2')->create();

        $response = $this->actingAs($this->admin())
            ->get(route('platform.departments.edit', $department));

        $response->assertOk();
        $response->assertSee('Department accent', false);
        $response->assertSee('Department surface background', false);
        $response->assertSee('Department logo', false);

        // BRAND-011: nothing else is a department override.
        $response->assertDontSee('Muted foreground text', false);
        $response->assertDontSee('Focus indicator', false);
    }

    public function test_an_admin_can_set_and_clear_department_branding_from_orchid(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['code' => 'RANGERS']);

        $this->actingAs($this->admin())
            ->post(
                route('platform.departments.edit', ['department' => $department, 'method' => 'save']),
                $this->departmentPayload($department, [
                    'set_accent' => '1',
                    'accent' => '#1f5f4b',
                    'set_surface' => '1',
                    'surface' => '#eef6f2',
                ]),
            )
            ->assertRedirect(route('platform.departments'));

        $department->refresh();
        $this->assertSame('#1f5f4b', $department->branding_accent_color);
        $this->assertSame('#eef6f2', $department->branding_surface_color);

        // A colour input cannot express "unset", so clearing is explicit.
        $this->actingAs($this->admin())
            ->post(
                route('platform.departments.edit', ['department' => $department, 'method' => 'save']),
                $this->departmentPayload($department, [
                    'set_accent' => '1',
                    'accent' => '#1f5f4b',
                    // Unchecked: the colour input still posts a value, and the
                    // checkbox is what says the department has one.
                    'set_surface' => '0',
                    'surface' => '#eef6f2',
                ]),
            )
            ->assertRedirect(route('platform.departments'));

        $department->refresh();
        $this->assertSame('#1f5f4b', $department->branding_accent_color);
        $this->assertNull($department->branding_surface_color);
    }

    public function test_a_department_background_that_hides_state_is_refused_from_orchid(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['code' => 'RANGERS']);

        $this->actingAs($this->admin())
            ->from(route('platform.departments.edit', $department))
            ->post(
                route('platform.departments.edit', ['department' => $department, 'method' => 'save']),
                $this->departmentPayload($department, [
                    'set_surface' => '1',
                    'surface' => '#cc792f',
                ]),
            )
            ->assertSessionHasErrors('branding.accent');

        $this->assertNull($department->fresh()->branding_surface_color);
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    private function organizationPayload(Organization $organization, array $branding = []): array
    {
        return [
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'default_ic_department_id' => '',
                'active_inactive_threshold_years' => 2,
                'prospective_inactive_threshold_years' => 1,
                'calendar_year_start_month' => 1,
                'calendar_year_start_day' => 1,
            ],
            'branding' => array_merge([
                'display_name' => '',
                'department_branding_enabled' => '1',
                'palette' => array_merge(BrandingPalette::meridianDefault()->toArray(), [
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
                ]),
            ], $branding),
        ];
    }

    public function test_saving_a_department_logo_alone_does_not_post_black_as_a_colour(): void
    {
        // The bug this exists for: `<input type="color">` has no empty state,
        // so a department with no colours set posts #000000 for both. Saving
        // the screen just to attach a logo was then refused with four contrast
        // failures against black — none of which the operator had chosen.
        Storage::fake('attachments');

        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['code' => 'RANGERS']);

        $payload = $this->departmentPayload($department, [
            // What the browser sends for two untouched colour inputs.
            'accent' => '#000000',
            'surface' => '#000000',
        ]);
        $payload['branding']['logo'] = UploadedFile::fake()->image('rangers.png', 200, 200);

        $this->actingAs($this->admin())
            ->post(
                route('platform.departments.edit', ['department' => $department, 'method' => 'save']),
                $payload,
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('platform.departments'));

        $department->refresh();

        $this->assertNull($department->branding_accent_color);
        $this->assertNull($department->branding_surface_color);
        $this->assertNotNull($department->branding_logo_attachment_id);
    }

    public function test_the_department_screen_previews_the_stored_logo(): void
    {
        Storage::fake('attachments');

        $department = Department::factory()->create(['name' => 'Department of Public Works']);

        // With no logo, the slot shows the lettermark that renders in its
        // place rather than an empty box.
        $this->actingAs($this->admin())
            ->get(route('platform.departments.edit', $department))
            ->assertOk()
            ->assertSee('DPW', false)
            ->assertSee('No asset stored', false);

        $attachment = app(\App\Services\Branding\BrandingAssetService::class)->put(
            $department,
            Attachment::BRANDING_SLOT_DEPARTMENT_LOGO,
            $this->pngBytes(),
            $this->admin(),
        );

        $this->actingAs($this->admin())
            ->get(route('platform.departments.edit', $department))
            ->assertOk()
            ->assertSee(route('branding.asset', ['attachment' => $attachment->getKey()]), false);
    }

    public function test_the_organization_screen_previews_the_stored_logo(): void
    {
        Storage::fake('attachments');

        $organization = Organization::factory()->branded('Deep Harbor Collective')->create();

        $this->actingAs($this->admin())
            ->get(route('platform.organizations.edit', $organization))
            ->assertOk()
            ->assertSee('DHC', false);

        $attachment = app(\App\Services\Branding\BrandingAssetService::class)->put(
            $organization,
            Attachment::BRANDING_SLOT_COMPACT_MARK,
            $this->pngBytes(),
            $this->admin(),
        );

        // The point of the preview: after a save, the operator can see that
        // the upload worked.
        $this->actingAs($this->admin())
            ->get(route('platform.organizations.edit', $organization))
            ->assertOk()
            ->assertSee(route('branding.asset', ['attachment' => $attachment->getKey()]), false);
    }

    public function test_saving_an_unbranded_organization_does_not_silently_brand_it(): void
    {
        // The palette inputs always post something. An operator who saved the
        // screen without touching a colour has not asked to replace Meridian's
        // identity, so `is_branded` must stay false.
        $organization = Organization::factory()->create(['slug' => 'idaho-burners']);

        $payload = $this->organizationPayload($organization);
        $payload['branding']['palette'] = BrandingPalette::meridianDefault()->toArray();

        $this->actingAs($this->admin())
            ->post(
                route('platform.organizations.edit', ['organization' => $organization, 'method' => 'save']),
                $payload,
            )
            ->assertRedirect(route('platform.organizations'));

        $this->assertNull($organization->fresh()->branding_palette_json);
        $this->assertFalse($organization->fresh()->hasBrandingProfile());
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

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    private function departmentPayload(Department $department, array $branding = []): array
    {
        return [
            'department' => [
                'organization_id' => $department->organization_id,
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
            ],
            'branding' => $branding,
        ];
    }
}
