<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Department;

use App\Models\Department;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class DepartmentListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'departments';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (Department $department) => Link::make($department->name)
                    ->route('platform.departments.edit', $department->id)),

            TD::make('code', __('Code'))
                ->sort()
                ->cantHide()
                ->filter(Input::make()),

            TD::make('organization.name', __('Organization'))
                ->render(fn (Department $department) => $department->organization?->name ?? __('Not configured')),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
