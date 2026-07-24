<?php

namespace App\Orchid\Layouts\Application;

use App\Models\EventApplication;
use App\Orchid\Filters\ApplicationDepartmentInterestFilter;
use App\Orchid\Filters\Scope\OrganizationScopeFilter;
use Orchid\Filters\Filter;
use Orchid\Screen\Layouts\Selection;

class ApplicationFiltersLayout extends Selection
{
    /**
     * Applications narrow by organization plus the permission-aware department
     * interest filter, which already scopes its options to departments the
     * reviewer may see.
     *
     * @return string[]|Filter[]
     */
    public function filters(): array
    {
        return [
            new OrganizationScopeFilter(EventApplication::class),
            ApplicationDepartmentInterestFilter::class,
        ];
    }
}
