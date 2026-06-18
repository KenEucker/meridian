<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class OrganizationEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('organization.name')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Name'))
                ->placeholder(__('Idaho Burners')),

            Input::make('organization.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Slug'))
                ->placeholder(__('idaho-burners'))
                ->help(__('Stable URL-safe organization identifier.')),

            Input::make('organization.active_inactive_threshold_years')
                ->type('number')
                ->min(0)
                ->max(100)
                ->title(__('Active-to-inactive threshold years'))
                ->help(__('Optional organization status configuration placeholder.')),

            Input::make('organization.prospective_inactive_threshold_years')
                ->type('number')
                ->min(0)
                ->max(100)
                ->title(__('Prospective-to-inactive threshold years'))
                ->help(__('Optional organization status configuration placeholder.')),

            Input::make('organization.calendar_year_start_month')
                ->type('number')
                ->min(1)
                ->max(12)
                ->title(__('Calendar year start month'))
                ->help(__('Use 1 through 12.')),

            Input::make('organization.calendar_year_start_day')
                ->type('number')
                ->min(1)
                ->max(31)
                ->title(__('Calendar year start day'))
                ->help(__('Use 1 through 31.')),
        ];
    }
}
