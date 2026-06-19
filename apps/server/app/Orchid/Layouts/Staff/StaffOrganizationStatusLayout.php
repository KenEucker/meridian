<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Staff;

use App\Models\StaffOrganizationStatus;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class StaffOrganizationStatusLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'organizationStatuses';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('organization.name', __('Organization'))
                ->render(fn (StaffOrganizationStatus $record) => $record->organization?->name ?? __('Not configured')),

            TD::make('status', __('Status'))
                ->render(fn (StaffOrganizationStatus $record) => StaffOrganizationStatus::statusLabels()[$record->status] ?? $record->status),

            TD::make('status_reason', __('Reason'))
                ->render(fn (StaffOrganizationStatus $record) => $record->status_reason ?? __('None')),

            TD::make('status_changed_at', __('Status changed'))
                ->usingComponent(DateTimeSplit::class),
        ];
    }
}
