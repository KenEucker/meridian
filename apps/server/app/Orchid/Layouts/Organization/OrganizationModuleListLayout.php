<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Models\Organization;
use App\Services\Modules\ModuleState;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * What each organization is entitled to, and what it is actually running.
 *
 * Three counts would fit in one column and are kept in three, because the two
 * ways a module ends up inactive belong to different people: the platform
 * withheld it, or the organization turned it off. An operator scanning this
 * list is looking for the first and has no business acting on the second.
 */
class OrganizationModuleListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'organizations';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Organization'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (Organization $organization) => Link::make($organization->name)
                    ->route('platform.organization-modules.edit', $organization->id)),

            TD::make('modules_active', __('Running'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (Organization $organization) => e(__(':active of :total', [
                    'active' => count($this->matching($organization, static fn (ModuleState $state): bool => $state->isActive())),
                    'total' => count($this->state($organization)),
                ]))),

            TD::make('modules_not_entitled', __('Not entitled'))
                ->render(fn (Organization $organization) => e($this->names(
                    $this->matching($organization, static fn (ModuleState $state): bool => ! $state->entitled),
                ))),

            TD::make('modules_disabled', __('Entitled, turned off by the organization'))
                ->render(fn (Organization $organization) => e($this->names(
                    $this->matching(
                        $organization,
                        static fn (ModuleState $state): bool => $state->entitled && ! $state->enabled,
                    ),
                ))),
        ];
    }

    /**
     * @return list<ModuleState>
     */
    private function state(Organization $organization): array
    {
        $state = $organization->getAttribute('module_state');

        return is_array($state) ? array_values(array_filter(
            $state,
            static fn (mixed $entry): bool => $entry instanceof ModuleState,
        )) : [];
    }

    /**
     * @param  callable(ModuleState): bool  $predicate
     * @return list<ModuleState>
     */
    private function matching(Organization $organization, callable $predicate): array
    {
        return array_values(array_filter($this->state($organization), $predicate));
    }

    /**
     * @param  list<ModuleState>  $states
     */
    private function names(array $states): string
    {
        if ($states === []) {
            return __('None');
        }

        return implode(', ', array_map(
            static fn (ModuleState $state): string => $state->label(),
            $states,
        ));
    }
}
