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
 *
 * The identifier is here because a technician setting a machine up has nowhere
 * else to read it (M18.64). A Kiosk in setup asks for the identifier of the
 * workstation it is, and until this column existed the only ways to obtain one
 * were `php artisan meridian:shared-workstation` and a database query — neither
 * of which is available to the operator this screen is written for. It is not a
 * credential (AUTH-030): signing in still requires a login code the node issued
 * to a named person, and the workstation still has to be trusted.
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

            // Selectable rather than pretty: this value is read off the screen
            // and typed into a machine's setup field, so it is rendered whole
            // and in a monospaced face rather than truncated.
            TD::make('id', __('Identifier'))
                ->render(fn (SharedWorkstation $workstation) => '<code class="user-select-all">'
                    .e((string) $workstation->getKey())
                    .'</code>'),

            // The workstation's typed identifier for the scan path's fallback
            // (M18.59; technical spec 13.4). Assigned when the workstation is
            // pinned, because "unique per event" needs an event to be unique
            // in — an unpinned machine has none yet, and says so.
            TD::make('short_code', __('Workstation code'))
                ->render(fn (SharedWorkstation $workstation) => $workstation->short_code === null
                    ? e(__('Assigned when pinned'))
                    : '<code>'.e((string) $workstation->short_code).'</code>'),

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
