<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Event;

use App\Models\Event;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class EventListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'events';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Name'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (Event $event) => Link::make($event->name)
                    ->route('platform.events.edit', $event->id)),

            TD::make('slug', __('Slug'))
                ->sort()
                ->cantHide()
                ->filter(Input::make()),

            TD::make('organization.name', __('Organization'))
                ->render(fn (Event $event) => $event->organization?->name ?? __('Not configured')),

            TD::make('starts_at', __('Starts'))
                ->render(fn (Event $event) => $this->eventDate($event, 'starts_at'))
                ->sort(),

            TD::make('ends_at', __('Ends'))
                ->render(fn (Event $event) => $this->eventDate($event, 'ends_at'))
                ->sort(),

            TD::make('timezone', __('Timezone'))
                ->sort()
                ->filter(Input::make()),
        ];
    }

    private function eventDate(Event $event, string $field): string
    {
        $date = $event->{$field};

        if ($date === null) {
            return __('TBD');
        }

        return sprintf(
            '<time class="mb-0 text-capitalize">%s<span class="text-muted d-block">%s</span></time>',
            e($date->translatedFormat('M j, Y')),
            e($date->translatedFormat('D, H:i')),
        );
    }
}
