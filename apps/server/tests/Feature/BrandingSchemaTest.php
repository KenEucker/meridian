<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Branding schema and model contract (M15A.2; BRAND-001, BRAND-004, BRAND-006,
 * BRAND-009, BRAND-013; data/API 10.1, 10.6).
 */
class BrandingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_table_has_branding_columns(): void
    {
        foreach ([
            'branding_display_name',
            'branding_palette_json',
            'branding_full_lockup_attachment_id',
            'branding_compact_mark_attachment_id',
            'department_branding_enabled',
            'branding_updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('organizations', $column),
                "organizations.{$column} is missing",
            );
        }
    }

    public function test_departments_table_has_branding_columns(): void
    {
        foreach ([
            'branding_logo_attachment_id',
            'branding_accent_color',
            'branding_surface_color',
            'branding_updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('departments', $column),
                "departments.{$column} is missing",
            );
        }
    }

    public function test_an_organization_starts_with_no_branding_and_department_overrides_enabled(): void
    {
        $organization = Organization::factory()->create();

        $this->assertNull($organization->branding_display_name);
        $this->assertNull($organization->branding_palette_json);
        $this->assertNull($organization->branding_full_lockup_attachment_id);
        $this->assertNull($organization->branding_compact_mark_attachment_id);
        $this->assertFalse($organization->hasBrandingProfile());

        // BRAND-013 is a switch an organization turns *off*; a new
        // organization has not asked to restrict its departments.
        $this->assertTrue($organization->department_branding_enabled);
    }

    public function test_branding_palette_round_trips_as_structured_data(): void
    {
        $palette = [
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
        ];

        $organization = Organization::factory()->create([
            'branding_palette_json' => $palette,
        ]);

        $this->assertSame($palette, $organization->fresh()->branding_palette_json);
        $this->assertTrue($organization->fresh()->hasBrandingProfile());
    }

    public function test_display_name_falls_back_to_the_organization_name(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
            'branding_display_name' => null,
        ]);

        $this->assertSame('Idaho Burners', $organization->brandingDisplayName());

        $organization->forceFill(['branding_display_name' => 'Idaho Burners Collective'])->save();

        $this->assertSame('Idaho Burners Collective', $organization->fresh()->brandingDisplayName());
    }

    public function test_blank_display_name_is_treated_as_unset(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Idaho Burners',
            'branding_display_name' => '   ',
        ]);

        $this->assertSame('Idaho Burners', $organization->brandingDisplayName());
    }

    public function test_department_branding_columns_persist_and_report_a_profile(): void
    {
        $department = Department::factory()->branded('#1f5f4b', '#eef6f2')->create();

        $department->refresh();

        $this->assertSame('#1f5f4b', $department->branding_accent_color);
        $this->assertSame('#eef6f2', $department->branding_surface_color);
        $this->assertTrue($department->hasBrandingProfile());
        $this->assertFalse(Department::factory()->create()->hasBrandingProfile());
    }

    public function test_logo_references_resolve_to_attachments(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        $lockup = Attachment::factory()->brandingAsset(
            Attachment::MORPH_ORGANIZATION,
            (string) $organization->id,
            Attachment::BRANDING_SLOT_FULL_LOCKUP,
            $user,
        )->create();

        $mark = Attachment::factory()->brandingAsset(
            Attachment::MORPH_ORGANIZATION,
            (string) $organization->id,
            Attachment::BRANDING_SLOT_COMPACT_MARK,
            $user,
        )->create();

        $departmentLogo = Attachment::factory()->brandingAsset(
            Attachment::MORPH_DEPARTMENT,
            (string) $department->id,
            Attachment::BRANDING_SLOT_DEPARTMENT_LOGO,
            $user,
        )->create();

        $organization->forceFill([
            'branding_full_lockup_attachment_id' => $lockup->id,
            'branding_compact_mark_attachment_id' => $mark->id,
        ])->save();

        $department->forceFill([
            'branding_logo_attachment_id' => $departmentLogo->id,
        ])->save();

        $this->assertTrue($organization->fresh()->brandingFullLockup->is($lockup));
        $this->assertTrue($organization->fresh()->brandingCompactMark->is($mark));
        $this->assertTrue($department->fresh()->brandingLogo->is($departmentLogo));

        $this->assertTrue($lockup->isBrandingAsset());
        $this->assertSame(Attachment::BRANDING_SLOT_FULL_LOCKUP, $lockup->brandingSlot());
        $this->assertFalse($lockup->isFieldReportPhoto());
    }

    public function test_a_branding_attachment_records_no_origin_device(): void
    {
        $organization = Organization::factory()->create();

        $attachment = Attachment::factory()->brandingAsset(
            Attachment::MORPH_ORGANIZATION,
            (string) $organization->id,
            Attachment::BRANDING_SLOT_FULL_LOCKUP,
        )->create();

        // A logo is uploaded from a browser, so there is no trusted field
        // device to attribute it to. The column stays required in practice for
        // device-origin uploads such as Field Report photos.
        $this->assertNull($attachment->fresh()->origin_device_id);
    }

    public function test_a_branding_attachment_resolves_back_to_its_owner(): void
    {
        $organization = Organization::factory()->create();

        $attachment = Attachment::factory()->brandingAsset(
            Attachment::MORPH_ORGANIZATION,
            (string) $organization->id,
            Attachment::BRANDING_SLOT_COMPACT_MARK,
        )->create();

        $this->assertTrue($attachment->fresh()->attachable->is($organization));
    }
}
