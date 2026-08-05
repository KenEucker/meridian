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
                ->accept('.xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->title(__('Spreadsheet or CSV file'))
                ->help(__('Upload the .xlsx workbook the rows were built in, or a CSV exported from it. The first sheet of a workbook is the one imported. Or paste the rows below instead.')),

            TextArea::make('csv')
                ->rows(10)
                ->title(__('Or paste CSV'))
                ->placeholder("email,name\nvera.staff@example.org,Vera Staff")
                ->help(__('Required columns: email, name. Column order does not matter and extra columns are ignored. Rows match existing accounts by email address, so the same file can be imported again after corrections.')),
        ];
    }
}
