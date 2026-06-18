<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Team;

use App\Models\Department;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class TeamEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('team.department_id')
                ->fromModel(Department::class, 'name')
                ->required()
                ->title(__('Department'))
                ->help(__('Teams are persistent groups within one department.')),

            Input::make('team.name')
                ->type('text')
                ->max(255)
                ->required()
                ->set('data-meridian-slug-target', 'team[code]')
                ->set('data-meridian-slug-format', 'code')
                ->title(__('Name'))
                ->placeholder(__('Operators')),

            Input::make('team.code')
                ->type('text')
                ->max(64)
                ->required()
                ->title(__('Code'))
                ->placeholder(__('OPERATORS'))
                ->help(__('Unique within the department.')),

            Input::make('team.description')
                ->type('text')
                ->title(__('Description'))
                ->placeholder(__('Radio operators and dispatch support.')),
        ];
    }
}
