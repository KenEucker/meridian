<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Department;
use App\Models\Organization;

/**
 * Loads the branding profile for an organization (M15A.4).
 *
 * An unknown, missing, or archived organization resolves to
 * {@see BrandingProfile::meridian()} rather than raising. Branding is chrome:
 * a surface that cannot work out whose system it is should render Meridian's
 * own identity, not fail to render. The alternative — a 404 on the stylesheet
 * route — would take the entire palette down with it.
 */
class BrandingResolver
{
    public function forOrganizationId(?string $organizationId): BrandingProfile
    {
        if ($organizationId === null || $organizationId === '') {
            return BrandingProfile::meridian();
        }

        $organization = Organization::query()->find($organizationId);

        return $organization instanceof Organization
            ? $this->forOrganization($organization)
            : BrandingProfile::meridian();
    }

    public function forOrganization(Organization $organization): BrandingProfile
    {
        // Archived departments keep their branding rows and are still named by
        // historical records, so they are resolved alongside active ones; a
        // badge on a closed shift should not lose its accent because the
        // department was archived afterwards.
        $departments = Department::query()
            ->where('organization_id', $organization->getKey())
            ->where(function ($query): void {
                $query->whereNotNull('branding_logo_attachment_id')
                    ->orWhereNotNull('branding_accent_color')
                    ->orWhereNotNull('branding_surface_color');
            })
            ->get();

        return BrandingProfile::forOrganization($organization, $departments);
    }

    /**
     * The profile for a single department's organization, with that
     * department resolved. Used by surfaces that know a department but have
     * not already loaded the organization.
     */
    public function forDepartment(Department $department): BrandingProfile
    {
        return $this->forOrganizationId(
            $department->organization_id !== null ? (string) $department->organization_id : null,
        );
    }
}
