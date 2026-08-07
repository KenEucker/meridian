<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Models\User;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Session\SessionContextException;
use Illuminate\Support\Carbon;

/**
 * Composes the offline read set technical spec 9.3 describes (CLIENT-021,
 * CLIENT-022, MOD-016; ADR-0003).
 *
 * The set is composed per request from the caller's live grants and stored
 * nowhere. That is what carries CLIENT-022 without a mechanism of its own: a
 * revoked grant, an archived membership, or a deactivated module changes the
 * next set the device is handed, because there is no earlier answer to serve.
 *
 * Two boundaries apply, in this order:
 *
 * 1. **Effective roles and memberships** ({@see OfflineReadSetScopeResolver}).
 *    A device does not hold records its user could not retrieve through the
 *    API, and the resolver that answers that for every other read answers it
 *    here (technical spec 11A.7).
 * 2. **Active modules** ({@see ActiveModuleResolver}). A device does not hold
 *    records belonging to a module the organization does not run, regardless of
 *    what its user is permitted to read (MOD-016). This is applied to the
 *    section rather than to the caller: a section states the module that owns
 *    it, and a module-owned section is recomposed from only the organizations
 *    running that module.
 *
 * The composer writes nothing, refuses nothing beyond an unresolvable event
 * context, and records no audit event. It is a read.
 */
class OfflineReadSetComposer
{
    /**
     * @param  list<OfflineReadSetContributor>  $contributors
     */
    private readonly array $contributors;

    public function __construct(
        private readonly OfflineReadSetScopeResolver $scopes,
        private readonly ActiveModuleResolver $modules,
        RegularStaffSections $regularStaff,
        DepartmentLogisticsSections $logistics,
        DepartmentOperationsSections $operations,
        DepartmentPlanningSections $planning,
        ShiftLeadSections $shiftLead,
        DepartmentLeadSections $departmentLead,
    ) {
        /*
         * The lists of technical spec 9.3: the regular-staff one every staff
         * member receives, then the five a role adds to it. They compose from
         * the same scope and are filtered by the same module boundary, which is
         * the point of the seam — a role-additive list is not a second endpoint
         * with a second set of rules, it is more sections of one set.
         *
         * A caller holding none of the roles receives only the first, because a
         * contributor with no grant to compose from returns no sections rather
         * than empty ones. An empty `logistics_staff_index` would be a claim
         * that a department has no staff, and a caller who holds no Logistics
         * role is not entitled to make it.
         *
         * Two lists of section 9.3 are deliberately not here. The event map
         * package has its own permission rules and its own sensitive-layer
         * boundary, and the IC list is guarded by "incidents should not be
         * greedily synced" — both are their own work rather than a section
         * appended to this one.
         */
        $this->contributors = [
            $regularStaff,
            $logistics,
            $operations,
            $planning,
            $shiftLead,
            $departmentLead,
        ];
    }

    /**
     * @throws SessionContextException when the requested event is not a context
     *                                 this caller may resolve on this node
     */
    public function compose(User $user, ?string $requestedEventId = null): OfflineReadSet
    {
        $scope = $this->scopes->resolve($user, $requestedEventId);

        $sections = [];
        $deferred = [];

        foreach ($this->contributors as $contributor) {
            $sections = [...$sections, ...$this->sectionsFrom($contributor, $scope)];
            $deferred = [...$deferred, ...$contributor->deferredFor($scope)];
        }

        return new OfflineReadSet(
            sections: $sections,
            deferred: $deferred,
            activeModules: $this->modules->activeForAll($scope->organizationIds),
            roleCodes: $scope->roleCodes,
            contextEvent: $scope->contextEvent,
            nodeLockedEventId: $scope->nodeLockedEventId,
            composedAt: Carbon::now(),
        );
    }

    /**
     * One contributor's sections, with the MOD-016 boundary applied.
     *
     * The common case is that every organization in scope runs every module the
     * contributor's sections belong to, and it is answered in one composition.
     * Where an organization has a module inactive, that module's sections are
     * recomposed from a scope narrowed to the organizations that do run it, so
     * an organization keeps its records rather than losing them to a different
     * organization's configuration.
     *
     * A module no organization in scope runs produces no section at all: the
     * key is absent rather than present and empty, because MOD-012 makes an
     * inactive module absent from the product rather than visibly switched off.
     *
     * @return list<OfflineReadSetSection>
     */
    private function sectionsFrom(OfflineReadSetContributor $contributor, OfflineReadSetScope $scope): array
    {
        $composed = $contributor->sectionsFor($scope);

        /** @var list<ModuleKey> $ownedModules */
        $ownedModules = [];

        foreach ($composed as $section) {
            if ($section->module !== null && ! in_array($section->module, $ownedModules, true)) {
                $ownedModules[] = $section->module;
            }
        }

        $organizationsRunning = [];
        $narrowingNeeded = false;

        foreach ($ownedModules as $module) {
            $running = array_values(array_filter(
                $scope->organizationIds,
                fn (string $organizationId): bool => $this->modules->isActive($organizationId, $module),
            ));

            $organizationsRunning[$module->value] = $running;
            $narrowingNeeded = $narrowingNeeded || $running !== $scope->organizationIds;
        }

        if (! $narrowingNeeded) {
            return $composed;
        }

        $kept = array_values(array_filter(
            $composed,
            static fn (OfflineReadSetSection $section): bool => $section->module === null,
        ));

        foreach ($ownedModules as $module) {
            $running = $organizationsRunning[$module->value];

            if ($running === []) {
                continue;
            }

            $source = $running === $scope->organizationIds
                ? $composed
                : $contributor->sectionsFor($scope->narrowedTo($running));

            foreach ($source as $section) {
                if ($section->module === $module) {
                    $kept[] = $section;
                }
            }
        }

        return $kept;
    }
}
