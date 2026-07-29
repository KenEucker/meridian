<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Import;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class AssignmentImportLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('file')
                ->type('file')
                ->accept('.csv,text/csv')
                ->title(__('CSV file'))
                ->help(__('Upload a CSV exported from a spreadsheet, or paste the rows below instead.')),

            TextArea::make('csv')
                ->rows(10)
                ->title(__('Or paste CSV'))
                ->placeholder("organization_slug,event_slug,department_code,shift_title,shift_starts_at,staff_email\nidaho-burners,idaho-decompression-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,vera.staff@example.org")
                ->help(__('Required columns: organization_slug, event_slug, department_code, shift_title, shift_starts_at, staff_email. Optional: team_code, needed only when one department runs two shifts with the same title and start. Import the shifts first. A staff member who may not work the shift is skipped with the reason; nobody is removed from a shift by an import.')),
        ];
    }
}
