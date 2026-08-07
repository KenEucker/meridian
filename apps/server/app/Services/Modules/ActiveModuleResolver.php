<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Domain\Modules\ModuleKey;

/**
 * The modules an organization is currently running (MOD-005, MOD-016).
 *
 * A module is *active* only when the platform entitles the organization to it
 * and the organization has enabled it. Both halves are stored on
 * `organization_modules`, which M19.11 creates; until then every organization
 * is entitled to and enabled for everything, which is MOD-009's own default and
 * the state every existing organization is in.
 *
 * This class exists now rather than with that table because MOD-016 is a
 * boundary the offline read set has to hold from its first line: a device does
 * not hold records belonging to a module the organization does not run,
 * whatever its user is permitted to read. Composing the set against a resolver
 * means M19.11 changes one method body and the boundary is already asserted
 * — the alternative is a read set written with no module concept at all and a
 * later task retrofitting one into every section.
 *
 * The organization is passed by id rather than as a model because the read set
 * spans several: a staff member reaches departments in more than one, and each
 * answers this question for itself.
 */
class ActiveModuleResolver
{
    /**
     * The modules active for this organization.
     *
     * @return list<ModuleKey>
     */
    public function activeFor(string $organizationId): array
    {
        return ModuleKey::cases();
    }

    /**
     * Whether one module is active for this organization.
     */
    public function isActive(string $organizationId, ModuleKey $module): bool
    {
        return in_array($module, $this->activeFor($organizationId), true);
    }

    /**
     * The modules active for *every* one of these organizations.
     *
     * The intersection rather than the union, because a section of the read set
     * carries rows from more than one organization at a time and a record must
     * not travel on the strength of a different organization running the module
     * that owns it. Sections are narrowed per organization before this is
     * consulted, so the intersection is what remains to state about the set as
     * a whole.
     *
     * @param  list<string>  $organizationIds
     * @return list<ModuleKey>
     */
    public function activeForAll(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $active = ModuleKey::cases();

        foreach (array_unique($organizationIds) as $organizationId) {
            $forOrganization = $this->activeFor($organizationId);

            $active = array_values(array_filter(
                $active,
                static fn (ModuleKey $module): bool => in_array($module, $forOrganization, true),
            ));
        }

        return $active;
    }
}
