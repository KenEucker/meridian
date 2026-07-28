<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Department;

/**
 * A department's own two values, already filtered through the organization
 * switch (BRAND-009, BRAND-011, BRAND-013).
 *
 * The switch is applied here rather than at each consumer. BRAND-013 keeps
 * logo and accent identity when overrides are off and drops only the surface
 * background, and a rule that subtle is one that every surface would otherwise
 * have to remember; getting it wrong at one call site would leak a background
 * an organization had switched off.
 */
final class DepartmentBranding
{
    private function __construct(
        public readonly string $departmentId,
        public readonly string $name,
        public readonly ?BrandingColor $accent,
        public readonly ?BrandingColor $surfaceBackground,
        public readonly ?string $logoAttachmentId,
    ) {}

    public static function forDepartment(Department $department, bool $overridesEnabled): self
    {
        return new self(
            departmentId: (string) $department->id,
            name: (string) $department->name,
            accent: BrandingColor::tryParse($department->branding_accent_color, 'department accent'),
            // Logo and accent survive the switch; the surface background does
            // not. That asymmetry is the whole content of BRAND-013.
            surfaceBackground: $overridesEnabled
                ? BrandingColor::tryParse($department->branding_surface_color, 'department surface')
                : null,
            logoAttachmentId: $department->branding_logo_attachment_id !== null
                ? (string) $department->branding_logo_attachment_id
                : null,
        );
    }

    /**
     * The letters shown when the department has no logo (BRAND-010).
     */
    public function lettermark(): string
    {
        return Lettermark::forName($this->name);
    }

    public function hasOverrides(): bool
    {
        return $this->accent !== null || $this->surfaceBackground !== null;
    }
}
