<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SharedWorkstationLoginCode;

use App\Models\SharedWorkstation;
use App\Models\User;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Relation;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

/**
 * The God Mode generation form: which user, and which trusted workstation
 * (AUTH-026, AUTH-028; technical spec 13.2).
 *
 * The user is a searched relation rather than a dropdown, because this screen is
 * used against a whole event's roster and a select of every known user would be
 * unusable at that size. The workstation list is short by design — a node has a
 * handful of trusted shared workstations — so it stays a select, which also makes
 * the choice reviewable at a glance before a credential is created.
 *
 * The event is deliberately absent: a code's event comes from the workstation's
 * pinned Kiosk context (technical spec 13.1), so offering an event here would
 * offer a combination that cannot exist.
 */
final class SharedWorkstationLoginCodeGenerateLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Relation::make('login_code.user_id')
                ->fromModel(User::class, 'name')
                ->searchColumns('name', 'email')
                ->required()
                ->title(__('User'))
                ->help(__('The code signs this user in at the workstation. God Mode may generate a code for any known user; a user generates their own from a device where they already hold a session.')),

            Select::make('login_code.shared_workstation_id')
                ->options($this->workstationOptions())
                ->required()
                ->empty(__('Select a trusted shared workstation'))
                ->title(__('Shared workstation'))
                ->help(__('Only trusted shared workstations whose pinned event this node holds are listed. The code is scoped to that workstation and to its pinned event, and is valid for six weeks.')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workstationOptions(): array
    {
        return SharedWorkstation::query()
            ->trusted()
            // A workstation whose pinned event this node does not hold cannot be
            // the scope of a code, so it is not offered as one.
            ->whereHas('event')
            ->with('event')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (SharedWorkstation $workstation): array => [
                (string) $workstation->getKey() => $workstation->name
                    .' — '.($workstation->event?->name ?? __('Unknown event')),
            ])
            ->all();
    }
}
