<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Permission;

use App\Models\PermissionRole;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Meridian's effective roles (technical spec 15.1, 15.2, 16.2).
 *
 * The scope column is the part that is easy to miss and hardest to reason
 * about later: the same role code means something different held at a node, an
 * organization, an event, a department, or a team, and "why can this person do
 * that?" is usually answered by the scope rather than by the capability list.
 */
class PermissionRoleListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'roles';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Role'))
                ->cantHide()
                ->render(fn (PermissionRole $role) => $role->name),

            TD::make('code', __('Code'))
                ->cantHide()
                ->render(fn (PermissionRole $role) => $role->code),

            TD::make('scope_type', __('Held at'))
                ->render(fn (PermissionRole $role) => __(ucfirst($role->scope_type))),

            TD::make('permissions_count', __('Capabilities'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (PermissionRole $role) => $role->permissions_count),

            TD::make('active_grants_count', __('Active team grants'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (PermissionRole $role) => $role->active_grants_count),

            TD::make('permissions', __('Grants'))
                ->render(fn (PermissionRole $role) => $role->permissions->isEmpty()
                    ? __('No capabilities registered yet')
                    : $role->permissions->pluck('code')->sort()->implode(', ')),
        ];
    }
}
