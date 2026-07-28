<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Event;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Event\EventBrandingLayout;
use App\Orchid\Layouts\Event\EventEditLayout;
use App\Orchid\Support\BrandingScreenSupport;
use App\Services\Branding\Lettermark;
use App\Services\Events\IncidentCommandDepartmentSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class EventEditScreen extends Screen
{
    use BrandingScreenSupport;

    /**
     * @var Event
     */
    public $event;

    /**
     * @return array<string, mixed>
     */
    public function query(Event $event): iterable
    {
        $logoUrl = $this->brandingAssetUrl($event->branding_logo_attachment_id);

        return [
            'event' => $event,
            'branding' => [
                'logo_url' => $logoUrl,
            ],
            'branding_slots' => [
                [
                    'label' => __('Event logo'),
                    'url' => $logoUrl,
                    'lettermark' => Lettermark::forName((string) $event->name),
                ],
            ],
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
        $layouts = [
            Layout::block(EventEditLayout::class)
                ->title(__('Event'))
                ->description(__('Events represent organization-produced occurrences with scheduled and active windows.')),
        ];

        // Branding needs an event to attach to, which arrives with the first
        // save — the same reason the organization, department, and team screens
        // defer theirs.
        if ($this->event->exists) {
            $layouts[] = Layout::view('orchid.branding.assets');
            $layouts[] = Layout::block(EventBrandingLayout::class)
                ->title(__('Branding'))
                ->description($this->brandingDescription());
        }

        return $layouts;
    }

    private function brandingDescription(): string
    {
        $base = __(
            'A logo only. On a node locked to this event the event mark replaces the organization mark in the '
            .'application header, the browser tab icon, and the desktop window icon, so staff who know the event but '
            .'not the company producing it can still tell which app they are in. Colors stay the organization\'s.'
        );

        $organizationId = $this->event->organization_id !== null
            ? (string) $this->event->organization_id
            : null;

        $lock = $this->brandingLockReason($organizationId);

        return $lock !== null ? $base.' '.$lock : $base;
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

        $validator = Validator::make($request->all(), [
            'event.organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'event.name' => ['required', 'string', 'max:255'],
            'event.slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'event.timezone' => ['required', 'timezone'],
            'event.ic_department_id' => ['nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'event.starts_at' => ['nullable', 'date'],
            'event.ends_at' => $endsAtRules,
            'event.active_event_window_starts_at' => ['nullable', 'date'],
            'event.active_event_window_ends_at' => $activeWindowEndsAtRules,
        ]);

        $validator->after(function ($validator) use ($request, $event): void {
            $departmentId = $request->input('event.ic_department_id');

            if (blank($departmentId)) {
                return;
            }

            if (! $event->exists) {
                $validator->errors()->add(
                    'event.ic_department_id',
                    'Incident Command department can only be selected after departments are assigned to the event.'
                );

                return;
            }

            $organizationId = (string) $request->input('event.organization_id');
            $isValidDepartment = Department::query()
                ->active()
                ->whereKey($departmentId)
                ->where('organization_id', $organizationId)
                ->whereExists(function ($query) use ($event, $departmentId): void {
                    $query->selectRaw('1')
                        ->from((new EventDepartmentAssignment)->getTable())
                        ->where('event_department_assignments.event_id', $event->id)
                        ->where('event_department_assignments.department_id', $departmentId)
                        ->whereNull('event_department_assignments.archived_at');
                })
                ->exists();

            if (! $isValidDepartment) {
                $validator->errors()->add(
                    'event.ic_department_id',
                    'Incident Command department must be an active department assigned to this event.'
                );
            }
        });

        /** @var array{event: array<string, mixed>} $validated */
        $validated = $validator->validate();
        $attributes = $validated['event'];
        $icDepartmentId = $attributes['ic_department_id'] ?? null;
        unset($attributes['ic_department_id']);

        $event->fill($attributes)->save();

        $department = filled($icDepartmentId)
            ? Department::query()->findOrFail($icDepartmentId)
            : null;

        try {
            app(IncidentCommandDepartmentSelectionService::class)->configureEventOverride(
                event: $event,
                department: $department,
                actor: $request->user() instanceof User ? $request->user() : null,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'event.ic_department_id' => $exception->getMessage(),
            ]);
        }

        $this->applyBrandingAsset(
            $request,
            $event,
            Attachment::BRANDING_SLOT_EVENT_LOGO,
            'branding.logo',
            'branding.remove_logo',
        );

        Toast::info(__('Event was saved.'));

        return redirect()->route('platform.events');
    }
}
