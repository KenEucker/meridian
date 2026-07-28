<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Department;
use App\Models\Organization;

/**
 * Everything a surface needs to render an organization's identity, resolved
 * once (M15A.4, M15A.8; BRAND-001 through BRAND-005, BRAND-013).
 *
 * A read model rather than the Eloquent rows, because the shell, the generated
 * stylesheet, the PDF exporter, the mailer, and the client bootstrap all need
 * the same answers — display name, mark, palette, whether department overrides
 * are on — and each of them working it out from columns would be four chances
 * to disagree about, say, whether a blank display name means "use the legal
 * name" or "use Meridian".
 */
final class BrandingProfile
{
    /**
     * @param  array<string, DepartmentBranding>  $departments  keyed by department id
     */
    private function __construct(
        public readonly ?string $organizationId,
        public readonly string $displayName,
        public readonly BrandingPalette $palette,
        public readonly bool $isBranded,
        /**
         * Whether the organization chose a palette, as opposed to merely
         * having a name or a logo.
         *
         * Separate from {@see $isBranded} because the two drive different
         * things and conflating them was a bug: an organization that uploaded
         * a logo but never opened the colour pickers had Meridian's default
         * palette applied *as if chosen*, which then overrode the dark theme
         * and painted a white background at night. Identity replacement
         * (BRAND-002) and palette replacement (BRAND-006) are separate
         * decisions and an organization may make either without the other.
         */
        public readonly bool $hasCustomPalette,
        public readonly bool $departmentOverridesEnabled,
        public readonly ?string $fullLockupAttachmentId,
        public readonly ?string $compactMarkAttachmentId,
        public readonly array $departments = [],
    ) {}

    /**
     * The profile every surface BRAND-003 protects renders with, and the one
     * an organization with no branding profile gets.
     */
    public static function meridian(): self
    {
        return new self(
            organizationId: null,
            displayName: 'Meridian',
            palette: BrandingPalette::meridianDefault(),
            isBranded: false,
            hasCustomPalette: false,
            departmentOverridesEnabled: true,
            fullLockupAttachmentId: null,
            compactMarkAttachmentId: null,
        );
    }

    /**
     * @param  iterable<Department>  $departments
     */
    public static function forOrganization(Organization $organization, iterable $departments = []): self
    {
        $overridesEnabled = (bool) $organization->department_branding_enabled;
        $resolved = [];

        foreach ($departments as $department) {
            $resolved[(string) $department->id] = DepartmentBranding::forDepartment(
                $department,
                $overridesEnabled,
            );
        }

        return new self(
            organizationId: (string) $organization->id,
            displayName: $organization->brandingDisplayName(),
            palette: BrandingPalette::fromStored($organization->branding_palette_json),
            isBranded: $organization->hasBrandingProfile(),
            hasCustomPalette: $organization->branding_palette_json !== null,
            departmentOverridesEnabled: $overridesEnabled,
            fullLockupAttachmentId: $organization->branding_full_lockup_attachment_id !== null
                ? (string) $organization->branding_full_lockup_attachment_id
                : null,
            compactMarkAttachmentId: $organization->branding_compact_mark_attachment_id !== null
                ? (string) $organization->branding_compact_mark_attachment_id
                : null,
            departments: $resolved,
        );
    }

    /**
     * The name that replaces Meridian's on the surfaces BRAND-002 names.
     *
     * Distinct from {@see $displayName}, which falls back to the legal
     * organization name so an admin surface always has something to show. An
     * organization that has not set up branding has not asked to be presented
     * as itself, and stamping "Bechtelar, Denesik and Ryan LLC" on a PDF that
     * used to say "Meridian" would be a worse outcome than either.
     */
    public function identityName(): string
    {
        return $this->isBranded ? $this->displayName : 'Meridian';
    }

    /**
     * The letters shown when there is no compact mark to show (BRAND-005).
     */
    public function lettermark(): string
    {
        return Lettermark::forName($this->identityName());
    }

    public function department(string $departmentId): ?DepartmentBranding
    {
        return $this->departments[$departmentId] ?? null;
    }

    /**
     * What the shell writes into `data-organization-branding` (UI
     * implementation contract 10.3).
     */
    public function documentAttribute(): string
    {
        return $this->isBranded ? 'applied' : 'default';
    }
}
