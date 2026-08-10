<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SharedWorkstationLoginCode;

use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\User;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Relation;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

/**
 * The God Mode generation form: which user, and which trusted workstation — or
 * none (AUTH-026, AUTH-028, AUTH-031; technical spec 13.2).
 *
 * The user is a searched relation rather than a dropdown, because this screen is
 * used against a whole event's roster and a select of every known user would be
 * unusable at that size. The workstation list is short by design — a node has a
 * handful of trusted shared workstations — so it stays a select, which also makes
 * the choice reviewable at a glance before a credential is created.
 *
 * Leaving the workstation empty issues an unbound code, which binds to the
 * first trusted workstation that redeems it (AUTH-031). An unbound code still
 * scopes to one event, and with no pinned context to take one from, the event
 * field is what supplies it — which is why the event is present here and
 * ignored the moment a workstation is named: a named workstation's pinned
 * event always wins, so the two cannot disagree.
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
                ->empty(__('No workstation — the code binds where it is first used'))
                ->title(__('Shared workstation'))
                ->help(__('Only trusted shared workstations whose pinned event this node holds are listed. Naming one scopes the code to that workstation and its pinned event. Leaving it empty issues an unbound code that works at the first trusted workstation it is entered at, within the event below.')),

            Select::make('login_code.event_id')
                ->options($this->eventOptions())
                ->empty(__('Select an event'))
                ->title(__('Event (for a code with no workstation)'))
                ->help(__('An unbound code is still scoped to one event. Ignored when a workstation is named — the workstation\'s pinned event wins.')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function eventOptions(): array
    {
        return Event::query()
            ->whereNull('archived_at')
            ->orderByDesc('starts_at')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Event $event): array => [
                (string) $event->getKey() => (string) $event->name,
            ])
            ->all();
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
