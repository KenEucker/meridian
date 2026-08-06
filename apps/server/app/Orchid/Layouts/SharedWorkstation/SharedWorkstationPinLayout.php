<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SharedWorkstation;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SharedWorkstation;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

/**
 * The God Mode pinning form (M18.32; technical spec 13.1, "shared workstations
 * are managed in God mode").
 *
 * Four flat selects rather than a cascade. A node holds a handful of trusted
 * workstations and one organization's events, so the lists are short, and a
 * combination that does not hold — an event of another organization, a
 * department that does not work the event — is refused by
 * {@see \App\Services\Kiosk\KioskPinnedContextService} with a sentence that says
 * which rule it broke. Filtering the options instead would hide the reason: an
 * operator whose department is missing from the list learns nothing about why.
 *
 * The department is optional because 13.1 makes it optional: a workstation is
 * pinned to one organization and one event, and to a department only when it is
 * somebody's desk rather than the site's front counter.
 */
final class SharedWorkstationPinLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('pin.shared_workstation_id')
                ->options($this->workstationOptions())
                ->required()
                ->empty(__('Select a trusted shared workstation'))
                ->title(__('Shared workstation'))
                ->help(__('Only trusted, unrevoked workstations are listed. A revoked one cannot hold a Kiosk context.')),

            Select::make('pin.organization_id')
                ->fromModel(Organization::class, 'name')
                ->empty(__('Keep the pinned organization'))
                ->title(__('Organization'))
                ->help(__('Whose site the machine stands on. Set it here on the first pin; afterwards an organizer changes the event from the Kiosk setup screen and this stays put.')),

            Select::make('pin.event_id')
                ->fromModel(Event::class, 'name')
                ->required()
                ->empty(__('Select an event'))
                ->title(__('Event'))
                ->help(__('The event the Kiosk works in, and the event every login code for this workstation is scoped to. It must belong to the pinned organization.')),

            Select::make('pin.department_id')
                ->fromModel(Department::class, 'name')
                ->empty(__('No department — the whole site'))
                ->title(__('Department'))
                ->help(__('Optional. Pins the machine to one desk, and must be a department that works the chosen event.')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workstationOptions(): array
    {
        return SharedWorkstation::query()
            ->trusted()
            ->with(['organization', 'event'])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (SharedWorkstation $workstation): array => [
                (string) $workstation->getKey() => sprintf(
                    '%s — %s',
                    (string) $workstation->name,
                    $workstation->hasPinnedKioskContext()
                        ? (string) ($workstation->event?->name ?? __('Unknown event'))
                        : __('Not pinned'),
                ),
            ])
            ->all();
    }
}
