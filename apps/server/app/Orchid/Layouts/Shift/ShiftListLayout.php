<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Shift;

use App\Models\Shift;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ShiftListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'shifts';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('title', __('Title'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (Shift $shift) => Link::make($shift->title)
                    ->route('platform.shifts.edit', $shift->id)),

            TD::make('event.name', __('Event'))
                ->render(fn (Shift $shift) => $shift->event?->name ?? __('Not configured')),

            TD::make('department.name', __('Department'))
                ->render(fn (Shift $shift) => $shift->department?->name
                    ?? $shift->department_name_snapshot
                    ?? __('Not configured')),

            TD::make('eligible_team_id', __('Eligible team'))
                ->render(fn (Shift $shift) => $shift->eligibleTeam?->name
                    ?? $shift->team_name_snapshot
                    ?? __('Not configured')),

            TD::make('starts_at', __('Starts'))
                ->usingComponent(DateTimeSplit::class)
                ->sort(),

            TD::make('ends_at', __('Ends'))
                ->usingComponent(DateTimeSplit::class)
                ->sort(),

            TD::make('capacity', __('Capacity'))
                ->sort()
                ->render(fn (Shift $shift) => $shift->capacity === null
                    ? __('No cap')
                    : (string) $shift->capacity),

            TD::make('cancelled_at', __('Status'))
                ->render(fn (Shift $shift) => $shift->isCancelled()
                    ? __('Cancelled')
                    : ($shift->starts_at !== null && now()->greaterThanOrEqualTo($shift->starts_at)
                        ? __('Started')
                        : __('Scheduled'))),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
