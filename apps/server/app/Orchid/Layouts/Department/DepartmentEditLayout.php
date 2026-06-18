<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Department;

use App\Models\Organization;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class DepartmentEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('department.organization_id')
                ->fromModel(Organization::class, 'name')
                ->required()
                ->title(__('Organization'))
                ->help(__('Departments are persistent operational units within one organization.')),

            Input::make('department.name')
                ->type('text')
                ->max(255)
                ->required()
                ->set('data-meridian-slug-target', 'department[code]')
                ->set('data-meridian-slug-format', 'code')
                ->title(__('Name'))
                ->placeholder(__('Rangers')),

            Input::make('department.code')
                ->type('text')
                ->max(64)
                ->required()
                ->title(__('Code'))
                ->placeholder(__('RANGERS'))
                ->help(__('Unique within the organization.')),

            Input::make('department.description')
                ->type('text')
                ->title(__('Description'))
                ->placeholder(__('Field operations and volunteer support.')),
        ];
    }
}
