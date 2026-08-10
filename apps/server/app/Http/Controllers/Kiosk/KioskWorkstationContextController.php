<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\Node;
use App\Models\Organization;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Services\Events\EventAdministrationAccess;
use App\Services\Kiosk\KioskPinnedContextException;
use App\Services\Kiosk\KioskPinnedContextService;
use App\Services\Node\NodeSetupService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Kiosk pinned context, read and changed (M18.32; UI-019 through UI-021;
 * technical spec 13.1; UI contract 18.1).
 *
 * A Kiosk has to know, before anybody signs in, whether the machine it is
 * running on is pinned to an organization and an event. UI-020 forbids it from
 * working that out any other way — not from the viewport, not from the network,
 * not from the last route, not from cached event data, and not from whoever
 * signed in last — so the answer is a read, and the read has to be answerable to
 * a workstation holding no credential at all. A workstation with no session is
 * exactly the state this question is asked in.
 *
 * What that read discloses is bounded three ways. It answers only for a
 * workstation that is trusted and not revoked, so an id that names nothing on
 * this node is a 404 and an id that names a decommissioned machine is the same
 * 404. It carries the organization, event, and department the machine is pinned
 * to and nothing else — no staff, no shifts, no counts. And the workstation id
 * it is keyed by is not a credential (AUTH-030): entering the pinned event still
 * requires a login code the node issued to a named user.
 *
 * The write is the other half of UI-021. It sits behind a credential, answers to
 * `organization.events.manage`, and is the only path that changes a pinned
 * context outside God Mode.
 */
final class KioskWorkstationContextController extends Controller
{
    public function __construct(
        private readonly EventAdministrationAccess $access,
        private readonly KioskPinnedContextService $pinnedContext,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * What this machine is and what it is pinned to.
     *
     * Unauthenticated for the reason above, and shaped so the Kiosk can decide
     * between setup and the login screen from one field: `pinned` is true only
     * when both an organization and an event are on the record, which is the
     * UI-019 condition stated once on the node rather than re-derived on every
     * client that asks.
     */
    public function show(SharedWorkstation $sharedWorkstation): JsonResponse
    {
        abort_unless($sharedWorkstation->isTrusted(), 404);

        return response()->json($this->contextPayload($sharedWorkstation));
    }

    /**
     * The events this workstation may be pinned to, and who works each of them.
     *
     * Behind a credential, because it is a list of an organization's events and
     * the departments on them — the same rows `organizer.events` serves and to
     * the same holders. A workstation with no pinned organization has nothing to
     * offer, and says so with the refusal that names God Mode rather than with
     * an empty list that would read as "this organization runs no events".
     */
    public function options(Request $request, SharedWorkstation $sharedWorkstation): JsonResponse
    {
        abort_unless($sharedWorkstation->isTrusted(), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organization = $sharedWorkstation->organization()->first();

        if (! $organization instanceof Organization) {
            return $this->pinRefusal(KioskPinnedContextException::organizationUnpinned());
        }

        if (! $this->access->canManageEvents($user, $organization)) {
            return $this->authorityRefusal();
        }

        return response()->json(
            $this->contextPayload($sharedWorkstation) + ['options' => $this->eventOptions($organization)],
        );
    }

    /**
     * Change the pinned event, and the optional pinned department (UI-021).
     *
     * The organization is not a field. It is what God Mode registered along with
     * the trusted device behind the machine, and an organizer of one
     * organization is nobody in particular in another — so the authority check
     * and the scope of the change are the same organization by construction.
     */
    public function update(Request $request, SharedWorkstation $sharedWorkstation): JsonResponse
    {
        abort_unless($sharedWorkstation->isTrusted(), 404);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organization = $sharedWorkstation->organization()->first();

        if (! $organization instanceof Organization) {
            return $this->pinRefusal(KioskPinnedContextException::organizationUnpinned());
        }

        if (! $this->access->canManageEvents($user, $organization)) {
            return $this->authorityRefusal();
        }

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $department = ($validated['department_id'] ?? null) === null
            ? null
            : Department::query()->findOrFail((string) $validated['department_id']);

        try {
            $sharedWorkstation = $this->pinnedContext->pin(
                workstation: $sharedWorkstation,
                event: $event,
                department: $department,
                actor: $user,
                sourceContext: AuditEvent::SOURCE_API,
            );
        } catch (KioskPinnedContextException $exception) {
            return $this->pinRefusal($exception);
        }

        return response()->json(
            $this->contextPayload($sharedWorkstation) + ['options' => $this->eventOptions($organization)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contextPayload(SharedWorkstation $workstation): array
    {
        $organization = $workstation->organization()->first();
        $event = $workstation->event()->first();
        $department = $workstation->department()->first();

        $node = $this->nodes->activeNode();

        return [
            'shared_workstation' => [
                'id' => (string) $workstation->getKey(),
                'name' => (string) $workstation->name,
                // The typed fallback for a dead camera (M18.59; technical spec
                // 13.4): displayed on the locked Kiosk beside the QR. It
                // identifies the workstation and grants nothing.
                'short_code' => $workstation->short_code,
                'trusted' => true,
            ],
            // The node this workstation answers to, so the Kiosk can put the
            // issuing node into the QR and a phone pointed elsewhere refuses
            // before issuing anything (AUTH-034).
            'node' => $node instanceof Node ? [
                'id' => (string) $node->getKey(),
                'name' => (string) $node->node_name,
            ] : null,
            // UI-019 in one field: an organization and an event, both present.
            // A department is optional and never part of the verdict.
            'pinned' => $workstation->hasPinnedKioskContext(),
            'organization' => $organization instanceof Organization ? [
                'id' => (string) $organization->getKey(),
                'name' => (string) $organization->name,
            ] : null,
            'event' => $event instanceof Event ? $this->eventPayload($event) : null,
            'department' => $department instanceof Department ? [
                'id' => (string) $department->getKey(),
                'name' => (string) $department->name,
            ] : null,
            'context_pinned_at' => $workstation->context_pinned_at?->toIso8601String(),
        ];
    }

    /**
     * The event, with the window a Kiosk screen states as its operations context
     * (UI contract 18.1).
     *
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event): array
    {
        return [
            'id' => (string) $event->getKey(),
            'name' => (string) $event->name,
            'timezone' => (string) $event->timezone,
            'active_event_window_starts_at' => $event->active_event_window_starts_at?->toIso8601String(),
            'active_event_window_ends_at' => $event->active_event_window_ends_at?->toIso8601String(),
        ];
    }

    /**
     * The organization's events, each carrying the departments that work it.
     *
     * Per event rather than once, for the reason M18.31 sends the participating
     * list per event: a department pin is only admissible for a department
     * assigned to *that* event, and one organization-wide list would offer
     * choices the node would refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function eventOptions(Organization $organization): array
    {
        /** @var EloquentCollection<int, Event> $events */
        $events = Event::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('archived_at')
            ->orderByDesc('starts_at')
            ->orderBy('name')
            ->get();

        $participating = EventDepartmentAssignment::query()
            ->whereIn('event_id', $events->modelKeys())
            ->whereNull('archived_at')
            ->with('department')
            ->get()
            ->groupBy('event_id');

        return $events
            ->map(function (Event $event) use ($participating): array {
                $departments = ($participating[$event->getKey()] ?? collect())
                    ->map(fn (EventDepartmentAssignment $assignment): ?Department => $assignment->department)
                    ->filter(fn (?Department $department): bool => $department instanceof Department)
                    ->sortBy(fn (Department $department): string => (string) $department->name)
                    ->values()
                    ->map(fn (Department $department): array => [
                        'id' => (string) $department->getKey(),
                        'name' => (string) $department->name,
                    ])
                    ->all();

                return $this->eventPayload($event) + ['departments' => $departments];
            })
            ->all();
    }

    private function pinRefusal(KioskPinnedContextException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'reason' => $exception->reason,
        ], $exception->status);
    }

    /**
     * Refused for the same reason `organizer.events` refuses, in the same words:
     * changing which event a machine works is event administration.
     */
    private function authorityRefusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Changing this workstation\'s pinned context requires organizer authority for its organization.',
            'reason' => 'kiosk_context_forbidden',
        ], 403);
    }
}
