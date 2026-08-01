<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Event;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use DateTimeZone;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class EventEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('event.organization_id')
                ->fromModel(Organization::class, 'name')
                ->required()
                ->title(__('Organization'))
                ->help(__('Events are produced by one organization.')),

            Input::make('event.name')
                ->type('text')
                ->max(255)
                ->required()
                ->set('data-meridian-slug-target', 'event[slug]')
                ->set('data-meridian-slug-year-source', 'event[starts_at]')
                ->title(__('Name'))
                ->placeholder(__('Emberfall 2026')),

            Input::make('event.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Slug'))
                ->placeholder(__('emberfall-2026'))
                ->help(__('Stable URL-safe event identifier.')),

            Select::make('event.timezone')
                ->options($this->timezoneOptions())
                ->required()
                ->title(__('Timezone'))
                ->help(__('Choose the event-local timezone used for schedule display.')),

            Select::make('event.ic_department_id')
                ->options($this->incidentCommandDepartmentOptions())
                ->empty(__('Use organization default'), '')
                ->title(__('Incident Command department'))
                ->help(__('Optional event override. Choices are active departments assigned to this event.')),

            Input::make('event.starts_at')
                ->type('datetime-local')
                ->title(__('Starts at'))
                ->help(__('Leave blank when the event schedule is TBD.')),

            Input::make('event.ends_at')
                ->type('datetime-local')
                ->title(__('Ends at'))
                ->help(__('Leave blank when the event schedule is TBD.')),

            Input::make('event.active_event_window_starts_at')
                ->type('datetime-local')
                ->title(__('Active event window starts at')),

            Input::make('event.active_event_window_ends_at')
                ->type('datetime-local')
                ->title(__('Active event window ends at')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function timezoneOptions(): array
    {
        $timezones = DateTimeZone::listIdentifiers();

        return array_combine($timezones, $timezones);
    }

    /**
     * @return array<string, string>
     */
    private function incidentCommandDepartmentOptions(): array
    {
        $event = $this->query->get('event');

        if (! $event instanceof Event || ! $event->exists) {
            return [];
        }

        return Department::query()
            ->where('departments.organization_id', $event->organization_id)
            ->whereNull('departments.archived_at')
            ->whereExists(function ($query) use ($event): void {
                $query->selectRaw('1')
                    ->from((new EventDepartmentAssignment)->getTable())
                    ->whereColumn('event_department_assignments.department_id', 'departments.id')
                    ->where('event_department_assignments.event_id', $event->id)
                    ->whereNull('event_department_assignments.archived_at');
            })
            ->orderBy('departments.name')
            ->pluck('departments.name', 'departments.id')
            ->all();
    }
}
