<?php

namespace App\Orchid\Layouts\Application;

use App\Orchid\Filters\ApplicationDepartmentInterestFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class ApplicationFiltersLayout extends Selection
{
    /**
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            ApplicationDepartmentInterestFilter::class,
        ];
    }
}
