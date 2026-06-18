<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Models\Organization;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class OrganizationListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'organizations';

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
                ->render(fn (Organization $organization) => Link::make($organization->name)
                    ->route('platform.organizations.edit', $organization->id)),

            TD::make('slug', __('Slug'))
                ->sort()
                ->cantHide()
                ->filter(Input::make()),

            TD::make('calendar_year_start', __('Calendar Year Start'))
                ->render(fn (Organization $organization) => $this->calendarYearStart($organization)),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }

    private function calendarYearStart(Organization $organization): string
    {
        if ($organization->calendar_year_start_month === null || $organization->calendar_year_start_day === null) {
            return __('Not configured');
        }

        return sprintf('%02d/%02d', $organization->calendar_year_start_month, $organization->calendar_year_start_day);
    }
}
