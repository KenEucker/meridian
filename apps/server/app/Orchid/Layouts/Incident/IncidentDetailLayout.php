<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Incident;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * One incident, in full (M18.34; INC-001, INC-007, INC-014).
 *
 * Every field is read-only. Technical spec 22.3 does allow God Mode to repair
 * an incident, and this is not that screen: a repair has to capture a reason
 * and leave an INC-007 timeline entry behind, and a form that wrote fields
 * straight back would produce a record whose own history does not mention the
 * change. Visibility is what M18.34 asks for, and visibility is what this is.
 */
class IncidentDetailLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('incident.incident_number')
                ->title(__('Number'))
                ->readonly()
                ->help(__('Assigned by the server. An incident created offline carries its number from the node that accepted it.')),

            Input::make('incident.title')
                ->title(__('Title'))
                ->readonly(),

            Input::make('incident.status')
                ->title(__('Status'))
                ->readonly(),

            Input::make('incident.priority_label')
                ->title(__('Priority'))
                ->readonly(),

            Input::make('types_display')
                ->title(__('Types'))
                ->readonly(),

            Input::make('event_display')
                ->title(__('Event'))
                ->readonly(),

            Input::make('started_display')
                ->title(__('Started'))
                ->readonly(),

            Input::make('closed_display')
                ->title(__('Closed'))
                ->readonly(),

            Input::make('created_by_display')
                ->title(__('Opened by'))
                ->readonly(),

            Input::make('incident.location_name')
                ->title(__('Location'))
                ->readonly(),

            Input::make('incident.location_address')
                ->title(__('Address'))
                ->readonly(),

            TextArea::make('incident.location_details')
                ->title(__('Location details'))
                ->rows(3)
                ->readonly(),

            TextArea::make('staff_display')
                ->title(__('Staff on the incident'))
                ->rows(5)
                ->readonly(),

            TextArea::make('timeline_display')
                ->title(__('History'))
                ->rows(16)
                ->readonly()
                ->help(__('Append-only. A stricken entry is annotated rather than removed, so the record of what was said and then withdrawn survives.')),

            Input::make('field_reports_display')
                ->title(__('Linked Field Reports'))
                ->readonly()
                ->help(__('Reading a linked report itself follows the Field Report rules, not this screen\'s.')),
        ];
    }
}
