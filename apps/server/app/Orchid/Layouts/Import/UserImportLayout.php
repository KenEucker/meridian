<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Import;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class UserImportLayout extends Rows
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
                ->placeholder("email,name\nvera.staff@example.org,Vera Staff")
                ->help(__('Required columns: email, name. Column order does not matter and extra columns are ignored. Rows match existing accounts by email address, so the same file can be imported again after corrections.')),
        ];
    }
}
