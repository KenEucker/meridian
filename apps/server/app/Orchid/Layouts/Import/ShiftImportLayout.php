<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Import;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class ShiftImportLayout extends Rows
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
                ->placeholder("organization_slug,event_slug,department_code,team_code,title,starts_at,ends_at,capacity\nnorthwood-collective,emberfall-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,6")
                ->help(__('Required columns: organization_slug, event_slug, department_code, team_code, title, starts_at, ends_at. Optional: capacity, signup_opens_at, signup_closes_at, schedule_lock_at. Times without a timezone are read in the event timezone. Rows match existing shifts by event, department, team, title, and start, so changing a title or start creates a new shift instead of renaming one.')),
        ];
    }
}
