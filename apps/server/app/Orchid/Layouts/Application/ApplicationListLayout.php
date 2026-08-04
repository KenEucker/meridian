<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Application;

use App\Models\EventApplication;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ApplicationListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'applications';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('applicant_legal_name', __('Applicant'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (EventApplication $application) => Link::make($application->applicant_legal_name)
                    ->route('platform.applications.show', $application->id)),

            TD::make('applicant_email', __('Email'))
                ->sort()
                ->filter(Input::make()),

            // An organization-scoped application names no event (APP-001), and
            // says so rather than reading as an event row with a missing name.
            TD::make('event.name', __('Applied to'))
                ->render(fn (EventApplication $application) => $application->isOrganizationScoped()
                    ? __('The organization')
                    : ($application->event?->name ?? __('Not configured'))),

            TD::make('organization.name', __('Organization'))
                ->render(fn (EventApplication $application) => $application->organization?->name ?? __('Not configured')),

            TD::make('status', __('Status'))
                ->sort()
                ->filter(Select::make()->options(EventApplication::statusLabels()))
                ->render(fn (EventApplication $application) => $application->statusLabel()),

            TD::make('department_interests', __('Department interest'))
                ->render(fn (EventApplication $application) => $application->departmentInterestDisplay()),

            TD::make('submitted_at', __('Submitted'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
