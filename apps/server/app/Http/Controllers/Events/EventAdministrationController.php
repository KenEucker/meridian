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
use App\Services\Events\EventDepartmentParticipationService;
use App\Services\Events\IncidentCommandDepartmentSelectionService;
use App\Services\Node\EventAuthority;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event administration as a product surface (M18.29, M18.31; UI contract 12.6
 * `organizer.events`; ORG-006; PLACE-003; data/API 10.2, 10.6).
 *
 * One read answers with the organization's events, the departments participating
 * in each one, the departments that could join it, and — per event — where it
 * sits in its authority lifecycle. The departments are sent per event rather
 * than once because participation is per event and ORG-006 admits only a
 * department actively assigned to *that* event as its Incident Command, so a
 * single organization-wide list would offer choices the node would refuse.
 *
 * The participating list is one list rather than two. It is what an organizer
 * manages, and it is exactly the set the Incident Command designation may be
 * chosen from, so sending it twice would be two answers to one question that
 * could drift apart on a slow read.
 *
 * The authority block is context and not a gate. The `events` row is exempt
 * from event authority by design, so an organizer can close or extend the very
 * window that makes a node read-only for everything else; see
 * {@see EventAdministrationService} for why. What the read publishes is which
 * phase the event is in and which node holds authority for its other records,
 * so somebody editing an event mid-window knows what is happening elsewhere.
 *
 * Writes are separate commands rather than one upsert. Creating an event,
 * correcting one, and changing who works it are different acts with different
 * audit entries, and a single endpoint deciding which it was from the shape of
 * its body would be a place for a typo'd id to silently do the wrong one.
 */
final class EventAdministrationController extends Controller
{
    public function __construct(
        private readonly EventAdministrationAccess $access,
        private readonly EventAdministrationService $events,
        private readonly EventDepartmentParticipationService $participation,
        private readonly EventAuthority $authority,
        private readonly IncidentCommandDepartmentSelectionService $incidentCommand,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $this->access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        return response()->json($this->payload($organization));
    }

    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules() + [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $this->access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        try {
            $this->events->create($organization, $this->attributes($request, $validated), $user, AuditEvent::SOURCE_API);
        } catch (EventAdministrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules() + [
            'event_id' => ['required', 'uuid', 'exists:events,id'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $event = Event::query()->with('organization')->findOrFail((string) $validated['event_id']);
        $organization = $event->organization;

        if (! $organization instanceof Organization || ! $this->access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        try {
            $this->events->update($event, $this->attributes($request, $validated), $user, AuditEvent::SOURCE_API);
        } catch (EventAdministrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization));
    }

    /**
     * Add a department to an event, or restore one that was removed (M18.31;
     * data/API 10.6 `event_department_assignments`).
     */
    public function assignDepartment(Request $request): JsonResponse
    {
        return $this->changeParticipation(
            $request,
            fn (Event $event, Department $department, User $actor) => $this->participation->assign(
                event: $event,
                department: $department,
                actor: $actor,
                sourceContext: AuditEvent::SOURCE_API,
            ),
        );
    }

    /**
     * Remove a department from an event, unless it still holds the event's
     * Incident Command (ORG-006) or Placement (PLACE-003) designation.
     */
    public function removeDepartment(Request $request): JsonResponse
    {
        return $this->changeParticipation(
            $request,
            fn (Event $event, Department $department, User $actor) => $this->participation->remove(
                event: $event,
                department: $department,
                actor: $actor,
                sourceContext: AuditEvent::SOURCE_API,
            ),
        );
    }

    /**
     * The two participation commands, which differ only in what they call.
     *
     * @param  callable(Event, Department, User): mixed  $change
     */
    private function changeParticipation(Request $request, callable $change): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $event = Event::query()->with('organization')->findOrFail((string) $validated['event_id']);
        $organization = $event->organization;

        if (! $organization instanceof Organization || ! $this->access->canManageEvents($user, $organization)) {
            return $this->refusal();
        }

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        try {
            $change($event, $department, $user);
        } catch (EventAdministrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization));
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
    private function payload(Organization $organization): array
    {
        $organizationId = (string) $organization->getKey();

        $events = Event::query()
            ->where('organization_id', $organizationId)
            ->with([
                'icDepartment',
                'placementDepartment',
                // Participation is what the surface manages and what ORG-006
                // and PLACE-003 both read, so the relation is narrowed here to
                // active assignments of active departments and the row below
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

        /*
         * Every active department of the organization, held once and narrowed
         * per event below. The alternative is one query per event asking which
         * departments are not in it, which is the same answer arrived at as
         * many times as the organization has events.
         */
        $departments = Department::query()
            ->where('organization_id', $organizationId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

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
                ->map(fn (Event $event): array => $this->row($event, $departments))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  EloquentCollection<int, Department>  $departments
     * @return array<string, mixed>
     */
    private function row(Event $event, EloquentCollection $departments): array
    {
        $effectiveIc = $this->incidentCommand->effectiveDepartment($event);
        $participating = $event->participatingDepartments;
        $participatingIds = $participating->map(fn (Department $department): string => (string) $department->getKey());

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
            /*
             * The event's Placement designation (PLACE-002). Read-only here:
             * choosing it is M14.1's, along with the map authority PLACE-004
             * says it unlocks. It is published because the screen surface
             * specification asks event admin views to show when a department is
             * the event's Placement department, and because it is half of why a
             * removal can be refused.
             */
            'placement_department' => $event->placementDepartment === null ? null : [
                'id' => (string) $event->placementDepartment->getKey(),
                'name' => (string) $event->placementDepartment->name,
            ],
            /*
             * Which departments work this event (data/API 10.6). This is the
             * list the surface manages, and it is also the set ORG-006 admits
             * for Incident Command, so it is sent once and read for both.
             */
            'participating_departments' => $participating
                ->map(fn (Department $department): array => $this->participant($event, $department))
                ->values()
                ->all(),
            /*
             * The active departments of this organization that are not in this
             * event yet. Sent rather than left to the client to subtract,
             * because the client would need the organization's whole department
             * list to do the subtraction and this surface is not otherwise
             * about departments.
             */
            'assignable_departments' => $departments
                ->reject(fn (Department $department): bool => $participatingIds->contains((string) $department->getKey()))
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
                'phase' => $this->authority->phaseFor($event),
                'authoritative_node' => $this->authority->authoritativeNodeFor($event)?->node_name,
            ],
        ];
    }

    /**
     * One participating department, carrying why it may not be removed where
     * that is the case.
     *
     * The refusal sentence comes from the same method the command refuses with,
     * so what the surface says before the attempt and what the node says after
     * it cannot be two different explanations.
     *
     * @return array<string, mixed>
     */
    private function participant(Event $event, Department $department): array
    {
        $departmentId = (string) $department->getKey();

        return [
            'id' => $departmentId,
            'name' => (string) $department->name,
            'is_incident_command' => (string) $event->ic_department_id === $departmentId,
            'is_placement' => (string) $event->placement_department_id === $departmentId,
            'removal_refusal' => $this->participation->removalRefusal($event, $department),
        ];
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may administer this organization\'s events.',
        ], 403);
    }
}
