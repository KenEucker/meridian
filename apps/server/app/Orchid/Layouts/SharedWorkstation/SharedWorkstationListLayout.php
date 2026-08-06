<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SharedWorkstation;

use App\Models\SharedWorkstation;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * The trusted shared workstations on this node and what each is pinned to
 * (M18.32; technical spec 13.1).
 *
 * The column that matters is the last one: a workstation with no pinned
 * organization and event is a workstation whose Kiosk is sitting in setup, and
 * that is the state an operator is looking for on this page.
 */
final class SharedWorkstationListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'workstations';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Workstation'))
                ->cantHide()
                ->render(fn (SharedWorkstation $workstation) => e((string) $workstation->name)),

            TD::make('organization', __('Organization'))
                ->render(fn (SharedWorkstation $workstation) => e(
                    (string) ($workstation->organization?->name ?? __('Not pinned'))
                )),

            TD::make('event', __('Event'))
                ->render(fn (SharedWorkstation $workstation) => e(
                    (string) ($workstation->event?->name ?? __('Not pinned'))
                )),

            TD::make('department', __('Department'))
                ->render(fn (SharedWorkstation $workstation) => e(
                    (string) ($workstation->department?->name ?? __('Whole site'))
                )),

            TD::make('context_pinned_at', __('Pinned'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT),

            TD::make('kiosk_state', __('Kiosk state'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (SharedWorkstation $workstation) => $workstation->hasPinnedKioskContext()
                    ? __('Ready')
                    : __('In setup')),
        ];
    }
}
