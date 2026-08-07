<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Incident;

use App\Models\Incident;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Incident repair visibility (M18.34; UI contract 12.9).
 *
 * Most recently started first. Status and priority are their recorded values
 * rather than a badge, because a repair screen is read against the database and
 * a colour is one more thing to translate back.
 */
class IncidentListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'incidents';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('started_at', __('Started'))
                ->usingComponent(DateTimeSplit::class)
                ->sort()
                ->defaultHidden(false)
                ->cantHide(),

            TD::make('incident_number', __('Number'))
                ->sort()
                ->filter(Input::make())
                ->cantHide()
                ->render(fn (Incident $incident) => Link::make(
                    $incident->incident_number ?? __('Unnumbered'),
                )->route('platform.incidents.show', $incident->id)),

            TD::make('title', __('Title'))
                ->sort()
                ->filter(Input::make()),

            TD::make('status', __('Status'))
                ->sort(),

            TD::make('priority_label', __('Priority'))
                ->sort(),

            TD::make('event', __('Event'))
                ->render(fn (Incident $incident) => e($incident->event?->name ?? '—')),

            TD::make('location_name', __('Location'))
                ->render(fn (Incident $incident) => e($incident->location_name ?? '—')),

            TD::make('closed_at', __('Closed'))
                ->sort()
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (Incident $incident) => e(
                    $incident->closed_at?->toDayDateTimeString() ?? '—',
                )),
        ];
    }
}
