<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Department;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\Team;
use App\Services\Node\NodeSetupService;

/**
 * Loads the branding profile for an organization (M15A.4, BRAND-028).
 *
 * An unknown, missing, or archived organization resolves to
 * {@see BrandingProfile::meridian()} rather than raising. Branding is chrome:
 * a surface that cannot work out whose system it is should render Meridian's
 * own identity, not fail to render. The alternative — a 404 on the stylesheet
 * route — would take the entire palette down with it.
 *
 * The profile also carries the event this install is locked to, when there is
 * one, because whether the chrome shows the event's mark or the organization's
 * is a property of the install rather than of the signed-in user. A Kiosk at a
 * gate has no user at all, and the node is what knows which event it is running
 * — the same source the desktop wrapper already reads for its window icon.
 */
class BrandingResolver
{
    public function __construct(private readonly NodeSetupService $nodes) {}

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

        // Only teams that have actually uploaded a mark. A team with no logo
        // renders a lettermark the client derives from the name it already
        // has, so shipping a row for every team in the organization would grow
        // the payload — and the offline cache — with nothing in it (BRAND-025).
        $teams = Team::query()
            ->inOrganization((string) $organization->getKey())
            ->whereNotNull('branding_logo_attachment_id')
            ->get();

        return BrandingProfile::forOrganization(
            $organization,
            $departments,
            $teams,
            $this->lockedEventFor($organization),
        );
    }

    /**
     * The event this install is locked to, when it belongs to the organization
     * being resolved (BRAND-028).
     *
     * The organization check is the point rather than a formality. A central
     * node serves every organization it hosts and is not locked to anything, so
     * it must never hand one organization's chrome the event of another; and a
     * node whose lock has been repointed at a different organization's event
     * should fall back to the organization's own identity rather than showing a
     * stranger's mark.
     */
    private function lockedEventFor(Organization $organization): ?Event
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node || $node->event_id === null) {
            return null;
        }

        return Event::query()
            ->whereKey($node->event_id)
            ->where('organization_id', $organization->getKey())
            ->first();
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
