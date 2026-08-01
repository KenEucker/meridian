<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Incident;

use App\Models\IncidentType;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class IncidentTypeListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'incidentTypes';

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
                ->render(fn (IncidentType $type) => Link::make($type->name)
                    ->route('platform.incident-types.edit', $type->id)),

            TD::make('organization.name', __('Organization'))
                ->render(fn (IncidentType $type) => $type->organization?->name ?? __('Not configured')),

            TD::make('archived_at', __('State'))
                ->sort()
                ->render(fn (IncidentType $type) => $type->archived_at !== null
                    ? __('Archived')
                    : __('Active')),

            // Archiving a type is not a quiet no-op when incidents carry it.
            TD::make('incidents_count', __('Incidents'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (IncidentType $type) => (int) $type->incidents_count),

            TD::make('created_at', __('Added'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
