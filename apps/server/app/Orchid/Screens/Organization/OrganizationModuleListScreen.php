<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Models\Organization;
use App\Orchid\Layouts\Organization\OrganizationModuleListLayout;
use App\Services\Modules\ModuleStateService;
use Orchid\Screen\Screen;

/**
 * What every organization on this node is entitled to run (MOD-006, MOD-021;
 * technical spec 15A.6, 22.2).
 *
 * Every organization, and for each of them every module in the catalogue. That
 * is MOD-021 rather than a default: the central node serves many organizations
 * at once, so filtering this list by module state would hide a module for the
 * organizations that use it, and would remove the surface an operator needs in
 * order to turn one back on.
 *
 * The rule the whole module console follows is that enforcement is by
 * organization and console visibility is by node binding. This screen is the
 * "by organization" half — it shows state and does not act on it — and M19.14
 * is the other half, where a node bound to one organization stops advertising
 * that organization's inactive modules in operational navigation while keeping
 * this screen reachable.
 */
class OrganizationModuleListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(ModuleStateService $modules): iterable
    {
        $organizations = Organization::query()
            ->filters()
            ->defaultSort('name')
            ->paginate();

        $state = $modules->stateForAll(
            $organizations->getCollection()
                ->map(static fn (Organization $organization): string => (string) $organization->getKey())
                ->values()
                ->all(),
        );

        $organizations->getCollection()->each(
            static fn (Organization $organization) => $organization->setAttribute(
                'module_state',
                $state[(string) $organization->getKey()] ?? [],
            ),
        );

        return [
            'organizations' => $organizations,
        ];
    }

    public function name(): ?string
    {
        return 'Organization Modules';
    }

    public function description(): ?string
    {
        return 'Which of the eight modules each organization is entitled to, and which it is actually running. Entitlement is a God Mode decision; whether an entitled module is turned on is the organization\'s.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.organization-modules',
        ];
    }

    /**
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            OrganizationModuleListLayout::class,
        ];
    }
}
