<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Event;

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
                ->placeholder(__('Idaho Decompression 2026')),

            Input::make('event.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Slug'))
                ->placeholder(__('idaho-decompression-2026'))
                ->help(__('Stable URL-safe event identifier.')),

            Select::make('event.timezone')
                ->options($this->timezoneOptions())
                ->required()
                ->title(__('Timezone'))
                ->help(__('Choose the event-local timezone used for schedule display.')),

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
}
