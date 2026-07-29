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
                ->accept('.csv,text/csv')
                ->title(__('CSV file'))
                ->help(__('Upload a CSV exported from a spreadsheet, or paste the rows below instead.')),

            TextArea::make('csv')
                ->rows(10)
                ->title(__('Or paste CSV'))
                ->placeholder("organization_slug,department_code,name,code,description\nidaho-burners,RANGERS,Dirt,DIRT,Field rangers")
                ->help(__('Required columns: organization_slug, department_code, name, code. Optional: description. Rows match existing teams by department and team code, so the same file can be imported again after corrections.')),
        ];
    }
}
