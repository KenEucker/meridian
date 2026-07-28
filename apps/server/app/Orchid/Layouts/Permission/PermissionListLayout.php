<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Permission;

use App\Models\Permission;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Registered capabilities and which roles carry them.
 *
 * Read in this direction the catalog answers the question an operator actually
 * asks during an incident — "who can close this?" — which the role table
 * answers only by reading every row.
 */
class PermissionListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'permissions';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('code', __('Capability'))
                ->cantHide()
                ->render(fn (Permission $permission) => $permission->code),

            TD::make('description', __('Allows'))
                ->render(fn (Permission $permission) => $permission->description),

            TD::make('roles_count', __('Roles'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (Permission $permission) => $permission->roles_count),
        ];
    }
}
