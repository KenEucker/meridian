<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Import;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

class TeamImportLayout extends Rows
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
                ->placeholder("organization_slug,department_code,name,code,description\nnorthwood-collective,RANGERS,Dirt,DIRT,Field rangers")
                ->help(__('Required columns: organization_slug, department_code, name, code. Optional: description. Rows match existing teams by department and team code, so the same file can be imported again after corrections.')),
        ];
    }
}
