<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Event;

use App\Models\Event;
use App\Models\Organization;
use App\Orchid\Layouts\Event\EventEditLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class EventEditScreen extends Screen
{
    /**
     * @var Event
     */
    public $event;

    /**
     * @return array<string, Event>
     */
    public function query(Event $event): iterable
    {
        return [
            'event' => $event,
        ];
    }

    public function name(): ?string
    {
        return $this->event->exists ? 'Edit Event' : 'Create Event';
    }

    public function description(): ?string
    {
        return 'Event identity, organization ownership, and active window.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.events',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.events'),

            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(EventEditLayout::class)
                ->title(__('Event'))
                ->description(__('Events represent organization-produced occurrences with scheduled and active windows.')),
        ];
    }

    public function save(Request $request, Event $event): RedirectResponse
    {
        $endsAtRules = ['nullable', 'date'];
        $activeWindowEndsAtRules = ['nullable', 'date'];

        if ($request->filled('event.starts_at')) {
            $endsAtRules[] = 'after_or_equal:event.starts_at';
        }

        if ($request->filled('event.active_event_window_starts_at')) {
            $activeWindowEndsAtRules[] = 'after_or_equal:event.active_event_window_starts_at';
        }

        $validated = $request->validate([
            'event.organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'event.name' => ['required', 'string', 'max:255'],
            'event.slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'event.timezone' => ['required', 'timezone'],
            'event.starts_at' => ['nullable', 'date'],
            'event.ends_at' => $endsAtRules,
            'event.active_event_window_starts_at' => ['nullable', 'date'],
            'event.active_event_window_ends_at' => $activeWindowEndsAtRules,
        ]);

        $event->fill($validated['event'])->save();

        Toast::info(__('Event was saved.'));

        return redirect()->route('platform.events');
    }
}
