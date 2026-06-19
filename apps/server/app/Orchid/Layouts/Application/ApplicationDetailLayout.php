<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Application;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class ApplicationDetailLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('application.applicant_legal_name')
                ->title(__('Legal name'))
                ->readonly(),

            Input::make('application.applicant_email')
                ->title(__('Email'))
                ->readonly(),

            Input::make('event_name')
                ->title(__('Event'))
                ->readonly(),

            Input::make('organization_name')
                ->title(__('Organization'))
                ->readonly()
                ->help(__('Approval occurs at the organization level (APP-005).')),

            Input::make('status_label')
                ->title(__('Status'))
                ->readonly(),

            Input::make('department_interest_display')
                ->title(__('Department interest'))
                ->readonly()
                ->help(__('Non-binding intake signal only; not assignment, membership, access, routing, or team selection.')),

            Input::make('submitted_at_display')
                ->title(__('Submitted'))
                ->readonly(),

            Input::make('reviewed_at_display')
                ->title(__('Reviewed'))
                ->readonly(),

            Input::make('reviewed_by_display')
                ->title(__('Reviewed by'))
                ->readonly(),

            Input::make('application.decision_reason')
                ->title(__('Decision reason'))
                ->readonly(),

            Input::make('withdrawn_at_display')
                ->title(__('Withdrawn'))
                ->readonly(),

            Input::make('staff_display')
                ->title(__('Matched staff profile'))
                ->readonly(),
        ];
    }
}
