<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Team;

use App\Models\Team;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class TeamListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'teams';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (Team $team) => Link::make($team->name)
                    ->route('platform.teams.edit', $team->id)),

            TD::make('code', __('Code'))
                ->sort()
                ->cantHide()
                ->filter(Input::make()),

            TD::make('department.name', __('Department'))
                ->render(fn (Team $team) => $team->department?->name ?? __('Not configured')),

            TD::make('is_default', __('Default'))
                ->render(fn (Team $team) => $team->is_default ? __('Yes') : __('No')),

            TD::make('archived_at', __('Archived'))
                ->render(fn (Team $team) => $team->isArchived() ? __('Archived') : __('Active')),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
