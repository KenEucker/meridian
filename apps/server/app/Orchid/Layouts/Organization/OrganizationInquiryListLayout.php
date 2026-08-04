<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Models\OrganizationInquiry;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class OrganizationInquiryListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'inquiries';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('organization_name', __('Organization'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (OrganizationInquiry $inquiry) => Link::make($inquiry->organization_name)
                    ->route('platform.organization-inquiries.show', $inquiry->id)),

            TD::make('contact_name', __('Contact'))
                ->sort()
                ->filter(Input::make()),

            TD::make('contact_email', __('Email'))
                ->sort()
                ->filter(Input::make()),

            TD::make('status', __('Status'))
                ->sort()
                ->filter(Select::make()->options(OrganizationInquiry::statusLabels()))
                ->render(fn (OrganizationInquiry $inquiry) => $inquiry->statusLabel()),

            TD::make('reviewed_by', __('Reviewed by'))
                ->render(fn (OrganizationInquiry $inquiry) => $inquiry->reviewedBy?->name ?? __('Not reviewed')),

            TD::make('submitted_at', __('Submitted'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
