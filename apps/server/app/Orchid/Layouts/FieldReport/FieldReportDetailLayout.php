<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\FieldReport;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * One Field Report, in full (M18.34; FR-003, FR-007, FR-008, FR-012).
 *
 * Every field is read-only, and not as a precaution: {@see \App\Models\FieldReport}
 * throws on update and delete, and technical spec 22.3 rules out a God Mode
 * edit of a finalized body and any append or redaction workflow in Alpha 1. A
 * writable control here would be offering an exception the database does not
 * have.
 */
class FieldReportDetailLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('report.fra_number')
                ->title(__('FRA number'))
                ->readonly()
                ->help(__('Assigned by the server on acceptance. A report that has not reached a server yet carries its temporary local number instead.')),

            Input::make('report.temporary_local_number')
                ->title(__('Temporary local number'))
                ->readonly(),

            Input::make('report.title')
                ->title(__('Title'))
                ->readonly(),

            TextArea::make('report.body')
                ->title(__('Original body'))
                ->rows(12)
                ->readonly()
                ->help(__('The account as it was submitted. It never changes; corrections are appends.')),

            TextArea::make('appends_display')
                ->title(__('Appends'))
                ->rows(10)
                ->readonly()
                ->help(__('Additions made by the report\'s author after submission. Whoever took the report down for somebody else gains no authority to add to it.')),

            Input::make('event_display')
                ->title(__('Event'))
                ->readonly(),

            Input::make('department_display')
                ->title(__('Department'))
                ->readonly(),

            Input::make('team_display')
                ->title(__('Team'))
                ->readonly(),

            Input::make('author_display')
                ->title(__('Author'))
                ->readonly()
                ->help(__('The staff member whose account this is. Append authority follows them.')),

            Input::make('submitted_by_display')
                ->title(__('Taken by'))
                ->readonly(),

            Input::make('device_submitted_display')
                ->title(__('Submitted on the device'))
                ->readonly(),

            Input::make('server_received_display')
                ->title(__('Received by the server'))
                ->readonly(),

            Input::make('origin_device_display')
                ->title(__('Origin device'))
                ->readonly(),

            Input::make('origin_node_display')
                ->title(__('Origin node'))
                ->readonly(),

            Input::make('report.sync_status')
                ->title(__('Sync status'))
                ->readonly(),

            Input::make('photos_display')
                ->title(__('Photos'))
                ->readonly()
                ->help(__('Downloading a Field Report photo answers to its own capability rather than to console access, and Alpha 1 provides no console redaction or deletion of one.')),

            Input::make('incidents_display')
                ->title(__('Linked incidents'))
                ->readonly(),
        ];
    }
}
