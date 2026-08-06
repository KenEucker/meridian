<?php

declare(strict_types=1);

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Events\EventAdministrationAccess;
use App\Services\Events\EventAdministrationException;
use App\Services\Events\EventAdministrationService;
use App\Services\Events\IncidentCommandDepartmentSelectionService;
use App\Services\Node\EventAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event administration as a product surface (M18.29; UI contract 12.6
 * `organizer.events`; ORG-006; data/API 10.2).
 *
 * One read answers with the organization's events, the departments eligible to
 * carry each event's Incident Command designation, and — per event — where it
 * sits in its authority lifecycle. The departments are sent per event rather
 * than once because ORG-006 admits only a department actively assigned to
 * *that* event, so a single organization-wide list would offer choices the node
 * would refuse.
 *
 * The authority block is context and not a gate. The `events` row is exempt
 * from event authority by design, so an organizer can close or extend the very
 * window that makes a node read-only for everything else; see
 * {@see EventAdministrationService} for why. What the read publishes is which
 * phase the event is in and which node holds authority for its other records,
 * so somebody editing an event mid-window knows what is happening elsewhere.
 *
 * Writes are two commands rather than one upsert. Creating an event and
 * correcting one are different acts with different audit entries, and a single
 * endpoint deciding which it was from the presence of an id would be a place
 * for a typo'd id to silently create a second event.
 */
final class EventAdministrationController extends Controller
{
    public function index(
        Request $request,
        Organization $organization,
        EventAdministrationAccess $access,
        EventAuthority $authority,
        IncidentCommandDepartmentSelectionService $incidentCommand,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        return response()->json($this->payload($organization, $authority, $incidentCommand));
    }

    public function create(
        Request $request,
        EventAdministrationAccess $access,
        EventAdministrationService $events,
        EventAuthority $authority,
        IncidentCommandDepartmentSelectionService $incidentCommand,
    ): JsonResponse {
        $validated = $request->validate($this->rules() + [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        try {
            $events->create($organization, $this->attributes($request, $validated), $user, AuditEvent::SOURCE_API);
        } catch (EventAdministrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization, $authority, $incidentCommand));
    }

    public function update(
        Request $request,
        EventAdministrationAccess $access,
        EventAdministrationService $events,
        EventAuthority $authority,
        IncidentCommandDepartmentSelectionService $incidentCommand,
    ): JsonResponse {
        $validated = $request->validate($this->rules() + [
            'event_id' => ['required', 'uuid', 'exists:events,id'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $event = Event::query()->with('organization')->findOrFail((string) $validated['event_id']);
        $organization = $event->organization;

        if (! $organization instanceof Organization || ! $access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        try {
            $events->update($event, $this->attributes($request, $validated), $user, AuditEvent::SOURCE_API);
        } catch (EventAdministrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization, $authority, $incidentCommand));
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'timezone' => ['required', 'timezone'],
            'minimum_staff_age' => ['sometimes', 'nullable', 'integer'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'active_event_window_starts_at' => ['sometimes', 'nullable', 'date'],
            'active_event_window_ends_at' => ['sometimes', 'nullable', 'date'],
            'ic_department_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * The attributes the service writes.
     *
     * `ic_department_id` is carried through only when the request actually sent
     * it, because the service treats a present null as "clear the designation"
     * and an absent key as "leave it alone" — and validation would flatten the
     * two into one.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(Request $request, array $validated): array
    {
        $attributes = $validated;
        unset($attributes['organization_id'], $attributes['event_id'], $attributes['ic_department_id']);

        if ($request->has('ic_department_id')) {
            $attributes['ic_department_id'] = $validated['ic_department_id'] ?? null;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Organization $organization,
        EventAuthority $authority,
        IncidentCommandDepartmentSelectionService $incidentCommand,
    ): array {
        $organizationId = (string) $organization->getKey();

        $events = Event::query()
            ->where('organization_id', $organizationId)
            ->with([
                'icDepartment',
                // ORG-006 admits only an active department actively assigned to
                // the event, so the relation is narrowed here and the row below
                // reports what came back rather than filtering a second time.
                'participatingDepartments' => fn ($query) => $query
                    ->wherePivotNull('archived_at')
                    ->whereNull('departments.archived_at')
                    ->orderBy('departments.name'),
            ])
            ->orderByRaw('starts_at is null')
            ->orderByDesc('starts_at')
            ->orderBy('name')
            ->get();

        $defaultIcDepartment = Department::query()
            ->find($organization->default_ic_department_id);

        return [
            'organization_id' => $organizationId,
            /*
             * ORG-005: the organization default an event inherits when it names
             * no override of its own. Sent so the surface can say what "no
             * override" resolves to rather than showing an empty select and
             * leaving the reader to assume it means nobody.
             */
            'default_ic_department' => $defaultIcDepartment === null ? null : [
                'id' => (string) $defaultIcDepartment->getKey(),
                'name' => (string) $defaultIcDepartment->name,
            ],
            'events' => $events
                ->map(fn (Event $event): array => $this->row($event, $authority, $incidentCommand))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        Event $event,
        EventAuthority $authority,
        IncidentCommandDepartmentSelectionService $incidentCommand,
    ): array {
        $effectiveIc = $incidentCommand->effectiveDepartment($event);

        return [
            'id' => (string) $event->getKey(),
            'name' => (string) $event->name,
            'slug' => (string) $event->slug,
            'timezone' => (string) $event->timezone,
            'minimum_staff_age' => $event->minimum_staff_age,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'active_event_window_starts_at' => $event->active_event_window_starts_at?->toIso8601String(),
            'active_event_window_ends_at' => $event->active_event_window_ends_at?->toIso8601String(),
            'archived' => $event->isArchived(),
            'ic_department_id' => $event->ic_department_id !== null
                ? (string) $event->ic_department_id
                : null,
            /*
             * What Incident Command actually resolves to for this event, which
             * is the override where one is set and the organization default
             * otherwise (ORG-005, ORG-006). The surface shows this beside the
             * override control so "inherited" is a stated answer rather than a
             * blank.
             */
            'effective_ic_department' => $effectiveIc === null ? null : [
                'id' => (string) $effectiveIc->getKey(),
                'name' => (string) $effectiveIc->name,
                'inherited' => $event->ic_department_id === null,
            ],
            // ORG-006 admits only a department actively assigned to this event,
            // so the choices are the event's own participating departments.
            'ic_department_options' => $event->participatingDepartments
                ->map(fn (Department $department): array => [
                    'id' => (string) $department->getKey(),
                    'name' => (string) $department->name,
                ])
                ->values()
                ->all(),
            /*
             * Where the event sits in its lifecycle, and which node holds
             * authority for its other records while it runs. Context for an
             * organizer editing an event mid-window, not a gate on the edit —
             * the `events` row is deliberately exempt from event authority so
             * that the window can be closed from the node somebody is at.
             */
            'authority' => [
                'phase' => $authority->phaseFor($event),
                'authoritative_node' => $authority->authoritativeNodeFor($event)?->node_name,
            ],
        ];
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may administer this organization\'s events.',
        ], 403);
    }
}
