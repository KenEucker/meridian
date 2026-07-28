<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The write path for branding profiles (M15A.6, M15A.7; BRAND-006, BRAND-009,
 * BRAND-013, BRAND-014, BRAND-016, BRAND-020, BRAND-021).
 *
 * Both surfaces come through here so the order of operations is stated once:
 * authority and freeze first, then contrast, then the write, then the audit
 * record. Validating before checking authority would tell an on-site operator
 * their colors are fine and then refuse the save anyway; writing before
 * validating would need a compensating update, and a compensating update is a
 * repair path, which BRAND-016 does not allow.
 *
 * A department update is validated against its organization's palette rather
 * than against Meridian's defaults. A background that reads well under one
 * palette can hide text under another, and the department's surfaces render
 * with the organization's foreground.
 */
class BrandingAdminService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ContrastValidator $contrast,
        private readonly BrandingGovernance $governance,
    ) {}

    /**
     * Replace an organization's palette, display name, and department switch.
     *
     * @param  array{display_name?: string|null, palette?: array<string, string>|null, department_branding_enabled?: bool}  $attributes
     *
     * @throws BrandingValidationException|BrandingAuthorityException
     */
    public function updateOrganizationBranding(
        Organization $organization,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Organization {
        $this->governance->assertEditable((string) $organization->getKey(), 'organization branding');

        $palette = array_key_exists('palette', $attributes) && $attributes['palette'] !== null
            ? BrandingPalette::fromArray($attributes['palette'])
            : null;

        if ($palette !== null) {
            $this->contrast->assertOrganizationPalette($palette);
        }

        $displayName = array_key_exists('display_name', $attributes)
            ? $this->normalize($attributes['display_name'])
            : $organization->branding_display_name;

        $overridesEnabled = array_key_exists('department_branding_enabled', $attributes)
            ? (bool) $attributes['department_branding_enabled']
            : (bool) $organization->department_branding_enabled;

        // Turning the switch off does not clear the departments' stored
        // values. BRAND-013 disables overrides; it does not delete what a
        // department authored, and an organization that switches back on
        // should find its departments as they left them.
        return DB::transaction(function () use (
            $organization,
            $palette,
            $displayName,
            $overridesEnabled,
            $actor,
            $sourceContext,
        ): Organization {
            $before = $this->organizationSnapshot($organization);

            $organization->forceFill([
                'branding_display_name' => $displayName,
                'branding_palette_json' => $palette?->toArray() ?? $organization->branding_palette_json,
                'department_branding_enabled' => $overridesEnabled,
                'branding_updated_at' => now(),
            ])->save();

            $organization->refresh();
            $after = $this->organizationSnapshot($organization);

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $organization,
                    action: $before['palette'] === null && $after['palette'] !== null
                        ? 'branding.created'
                        : 'branding.updated',
                    actorUser: $actor,
                    organizationId: (string) $organization->getKey(),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $organization;
        });
    }

    /**
     * Reset an organization to Meridian's default palette.
     *
     * Clearing the palette is not the same as choosing Meridian's colors: a
     * null column means "no branding profile", which is what makes the shell
     * keep the Meridian name and mark (BRAND-002).
     *
     * @throws BrandingAuthorityException
     */
    public function clearOrganizationPalette(
        Organization $organization,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Organization {
        $this->governance->assertEditable((string) $organization->getKey(), 'organization branding');

        return DB::transaction(function () use ($organization, $actor, $sourceContext): Organization {
            $before = $this->organizationSnapshot($organization);

            $organization->forceFill([
                'branding_palette_json' => null,
                'branding_updated_at' => now(),
            ])->save();

            $organization->refresh();

            $this->audit->recordForEntity(
                entity: $organization,
                action: 'branding.updated',
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                before: $before,
                after: $this->organizationSnapshot($organization),
                sourceContext: $sourceContext,
            );

            return $organization;
        });
    }

    /**
     * Set a department's accent and surface background.
     *
     * @param  array{accent?: string|null, surface?: string|null}  $attributes
     *
     * @throws BrandingValidationException|BrandingAuthorityException
     */
    public function updateDepartmentBranding(
        Department $department,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Department {
        $organizationId = (string) $department->organization_id;

        $this->governance->assertEditable($organizationId, 'department branding');

        $organization = $department->organization;

        if ($organization instanceof Organization && ! $organization->department_branding_enabled) {
            throw BrandingValidationException::departmentOverridesDisabled();
        }

        $accent = array_key_exists('accent', $attributes)
            ? BrandingColor::tryParse($attributes['accent'], 'department accent')
            : BrandingColor::tryParse($department->branding_accent_color, 'department accent');

        $surface = array_key_exists('surface', $attributes)
            ? BrandingColor::tryParse($attributes['surface'], 'department surface')
            : BrandingColor::tryParse($department->branding_surface_color, 'department surface');

        $palette = BrandingPalette::fromStored($organization?->branding_palette_json);

        $this->contrast->assertDepartmentBranding($palette, $accent, $surface);

        return DB::transaction(function () use (
            $department,
            $organizationId,
            $accent,
            $surface,
            $actor,
            $sourceContext,
        ): Department {
            $before = $this->departmentSnapshot($department);

            $department->forceFill([
                'branding_accent_color' => $accent?->hex,
                'branding_surface_color' => $surface?->hex,
                'branding_updated_at' => now(),
            ])->save();

            $department->refresh();
            $after = $this->departmentSnapshot($department);

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $department,
                    action: $before['accent'] === null && $before['surface'] === null
                        ? 'branding.created'
                        : 'branding.updated',
                    actorUser: $actor,
                    organizationId: $organizationId,
                    departmentId: (string) $department->getKey(),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $department;
        });
    }

    /**
     * The validation result an admin surface shows before a save (BRAND-018).
     *
     * Same validator, same thresholds, no write. A preview that used different
     * rules from the save would be worse than no preview.
     *
     * @param  array<string, string>  $palette
     * @return array{valid: bool, failures: list<array<string, mixed>>}
     */
    public function previewOrganizationPalette(array $palette): array
    {
        $failures = $this->contrast->failuresForOrganizationPalette(
            BrandingPalette::fromArray($palette),
        );

        return [
            'valid' => $failures === [],
            'failures' => array_map(
                static fn (ContrastFailure $failure): array => $failure->toArray(),
                $failures,
            ),
        ];
    }

    /**
     * @return array{valid: bool, failures: list<array<string, mixed>>}
     */
    public function previewDepartmentBranding(
        Organization $organization,
        ?string $accent,
        ?string $surface,
    ): array {
        $failures = $this->contrast->failuresForDepartmentBranding(
            BrandingPalette::fromStored($organization->branding_palette_json),
            BrandingColor::tryParse($accent, 'department accent'),
            BrandingColor::tryParse($surface, 'department surface'),
        );

        return [
            'valid' => $failures === [],
            'failures' => array_map(
                static fn (ContrastFailure $failure): array => $failure->toArray(),
                $failures,
            ),
        ];
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{display_name: string|null, palette: array<string, string>|null, department_branding_enabled: bool, full_lockup_attachment_id: string|null, compact_mark_attachment_id: string|null}
     */
    private function organizationSnapshot(Organization $organization): array
    {
        return [
            'display_name' => $organization->branding_display_name,
            'palette' => $organization->branding_palette_json,
            'department_branding_enabled' => (bool) $organization->department_branding_enabled,
            'full_lockup_attachment_id' => $organization->branding_full_lockup_attachment_id !== null
                ? (string) $organization->branding_full_lockup_attachment_id
                : null,
            'compact_mark_attachment_id' => $organization->branding_compact_mark_attachment_id !== null
                ? (string) $organization->branding_compact_mark_attachment_id
                : null,
        ];
    }

    /**
     * @return array{accent: string|null, surface: string|null, logo_attachment_id: string|null}
     */
    private function departmentSnapshot(Department $department): array
    {
        return [
            'accent' => $department->branding_accent_color,
            'surface' => $department->branding_surface_color,
            'logo_attachment_id' => $department->branding_logo_attachment_id !== null
                ? (string) $department->branding_logo_attachment_id
                : null,
        ];
    }
}
