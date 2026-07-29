<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Permission;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Orchid\Layouts\Permission\PermissionListLayout;
use App\Orchid\Layouts\Permission\PermissionRoleListLayout;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * The Meridian permission catalog: effective roles, registered capabilities,
 * and the mapping between them (technical spec 15.1, 15.2, 16.2).
 *
 * This is the permission model the product runs on. It is distinct from the
 * administrative framework's own role list, which controls who may open this
 * console and nothing else — a distinction that is invisible from the
 * framework's screens and is the reason this one exists.
 *
 * Read-only, deliberately. The catalog is defined in code by
 * `App\Domain\Permissions\PermissionCatalog` and seeded from there, so it is a
 * property of the build rather than of the deployment. An operator who could
 * edit it here could give a role a capability the code does not know about, or
 * remove one the code depends on, and the next deployment would silently
 * disagree. What God Mode needs from the catalog is the ability to *see* it —
 * to answer "why can this person do that?" — and to notice when what is stored
 * has drifted from what the build expects.
 */
class PermissionCatalogScreen extends Screen
{
    /**
     * @var array<int, array<string, string>>
     */
    public $drift = [];

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        $roles = PermissionRole::query()
            ->with('permissions')
            ->withCount(['permissions', 'teamGrants as active_grants_count' => fn ($query) => $query->active()])
            ->orderBy('scope_type')
            ->orderBy('name')
            ->get();

        $drift = $this->drift($roles);

        return [
            'roles' => $roles,
            'permissions' => Permission::query()
                ->withCount('roles')
                ->orderBy('code')
                ->get(),
            'drift' => $drift,
            'hasDrift' => $drift !== [],
        ];
    }

    public function name(): ?string
    {
        return 'Permission Catalog';
    }

    public function description(): ?string
    {
        return 'Meridian effective roles and capabilities. Read-only: the catalog is defined by the build, not the deployment.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.permissions',
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.permissions.explainer'),
            Layout::view('orchid.permissions.drift'),

            // Vertical, because these blocks hold data tables rather than
            // forms. The framework's default puts the heading beside the
            // content and gives the content seven of twelve columns, which
            // suits a narrow form and wastes half the screen on a table of
            // capability codes.
            Layout::block(PermissionRoleListLayout::class)
                ->vertical()
                ->title(__('Effective roles'))
                ->description(__('Every role a person can hold in Meridian, the scope it is held at, and how many teams currently grant it.')),

            Layout::block(PermissionListLayout::class)
                ->vertical()
                ->title(__('Registered capabilities'))
                ->description(__('Every capability a role can carry. A capability with no roles is defined but not yet granted by anything.')),
        ];
    }

    /**
     * Compare what is stored against what this build defines.
     *
     * Three kinds of disagreement matter, and they fail differently. A role or
     * capability the build defines but the deployment does not have was never
     * seeded, and anything depending on it is broken now. One the deployment
     * has but the build does not was removed from the catalog and is dead
     * weight that may still be granted. A role whose capability set differs is
     * the dangerous one: it works, and it works differently from every other
     * node running this build.
     *
     * @param  \Illuminate\Support\Collection<int, PermissionRole>  $roles
     * @return array<int, array<string, string>>
     */
    private function drift($roles): array
    {
        $drift = [];

        $storedRoles = $roles->keyBy('code');
        $definedRoles = PermissionCatalog::roles();

        foreach (array_diff(array_keys($definedRoles), $storedRoles->keys()->all()) as $code) {
            $drift[] = [
                'subject' => $code,
                'kind' => __('Role'),
                'detail' => __('Defined by this build but not stored in this deployment.'),
            ];
        }

        foreach (array_diff($storedRoles->keys()->all(), array_keys($definedRoles)) as $code) {
            $drift[] = [
                'subject' => $code,
                'kind' => __('Role'),
                'detail' => __('Stored in this deployment but not defined by this build.'),
            ];
        }

        $storedPermissions = Permission::query()->pluck('code')->all();
        $definedPermissions = array_keys(PermissionCatalog::permissions());

        foreach (array_diff($definedPermissions, $storedPermissions) as $code) {
            $drift[] = [
                'subject' => $code,
                'kind' => __('Capability'),
                'detail' => __('Defined by this build but not stored in this deployment.'),
            ];
        }

        foreach (array_diff($storedPermissions, $definedPermissions) as $code) {
            $drift[] = [
                'subject' => $code,
                'kind' => __('Capability'),
                'detail' => __('Stored in this deployment but not defined by this build.'),
            ];
        }

        $definedMapping = PermissionCatalog::rolePermissions();

        foreach ($storedRoles as $code => $role) {
            if (! array_key_exists($code, $definedMapping)) {
                continue;
            }

            $stored = $role->permissions->pluck('code')->sort()->values()->all();
            $defined = collect($definedMapping[$code])->sort()->values()->all();

            if ($stored === $defined) {
                continue;
            }

            $drift[] = [
                'subject' => $code,
                'kind' => __('Role capabilities'),
                'detail' => __('Stored: :stored. Defined by this build: :defined.', [
                    'stored' => $stored === [] ? __('none') : implode(', ', $stored),
                    'defined' => $defined === [] ? __('none') : implode(', ', $defined),
                ]),
            ];
        }

        return $drift;
    }
}
