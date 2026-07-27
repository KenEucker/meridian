<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SyncConflict;

use App\Models\SyncConflict;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class SyncConflictListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'conflicts';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('entity_type', __('Entity type'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (SyncConflict $conflict) => Link::make($conflict->entity_type)
                    ->route('platform.sync-conflicts.show', $conflict->id)),

            TD::make('entity_id', __('Entity'))
                ->render(fn (SyncConflict $conflict) => e($conflict->entity_id)),

            TD::make('conflict_type', __('Conflict type'))
                ->sort()
                ->filter(Input::make()),

            TD::make('status', __('Status'))
                ->sort()
                ->filter(Select::make()->options(SyncConflict::statusLabels()))
                ->render(fn (SyncConflict $conflict) => $conflict->statusLabel()),

            TD::make('reason', __('Reason'))
                ->render(fn (SyncConflict $conflict) => e(\Illuminate\Support\Str::limit($conflict->reason, 80))),

            TD::make('created_at', __('Created'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
