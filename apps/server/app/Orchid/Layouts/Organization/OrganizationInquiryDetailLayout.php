<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Layouts\Rows;

/**
 * What the organization wrote (PUBLIC-002, PUBLIC-004). Every field is
 * read-only: this is somebody's message, and an operator editing it would be
 * editing the record of what was said.
 */
class OrganizationInquiryDetailLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('inquiry.organization_name')
                ->title(__('Organization name'))
                ->readonly()
                ->help(__('As the contact typed it. No organization record exists for it (PUBLIC-003).')),

            Input::make('inquiry.contact_name')
                ->title(__('Contact name'))
                ->readonly(),

            Input::make('inquiry.contact_email')
                ->title(__('Contact email'))
                ->readonly()
                ->help(__('Unverified. Nobody proved control of this address to submit the form.')),

            TextArea::make('inquiry.description')
                ->title(__('What the organization runs'))
                ->rows(8)
                ->readonly(),

            Input::make('submitted_at_display')
                ->title(__('Submitted'))
                ->readonly(),

            Input::make('status_label')
                ->title(__('Status'))
                ->readonly(),

            Input::make('reviewed_at_display')
                ->title(__('Last decision'))
                ->readonly(),

            Input::make('reviewed_by_display')
                ->title(__('Last decided by'))
                ->readonly(),
        ];
    }
}
