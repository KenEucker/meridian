<?php

namespace App\Http\Controllers\Incidents;

use App\Exceptions\IncidentSearchException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Services\Audit\AuditService;
use App\Services\Incidents\IncidentListPresetService;
use App\Services\Incidents\IncidentPayloadSerializer;
use App\Services\Incidents\IncidentReadAccess;
use App\Services\Incidents\IncidentSearchFilters;
use App\Services\Incidents\IncidentSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restricted IMS incident read transport (M11.5, M11.19).
 *
 * Data/API section 5.1 documents resource reads under
 * GET /api/events/{event}/incidents. Section 6.5 requires incident rows to be
 * returned only to IC-authorized users; UI hiding alone is insufficient.
 *
 * M11.19 adds explicit list search/filter/sort query parameters, pagination,
 * and the caller's saved filter presets. The IC gate still runs before any of
 * them are parsed, so an unauthorized actor learns nothing about the event's
 * incidents from a filtered, paged, or preset-bearing response.
 *
 * Serialization lives in {@see IncidentPayloadSerializer}, shared with the
 * offline read set's IC section so the stored copy and the live answer are one
 * shape.
 */
final class IncidentReadController extends Controller
{
    public function index(
        Request $request,
        Event $event,
        IncidentReadAccess $access,
        IncidentPayloadSerializer $serializer,
        IncidentSearchService $search,
        IncidentListPresetService $presets,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canViewIncidents($user, $event)) {
            return $this->restrictedResponse();
        }

        try {
            $filters = IncidentSearchFilters::fromQuery($request->query());
        } catch (IncidentSearchException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $page = $search->search($user, $event, $filters);
        $incidents = $page->getCollection()
            ->map(fn (Incident $incident): array => $serializer->payload($incident))
            ->values();

        return response()->json([
            'event_id' => $event->id,
            'filters' => $filters->toArray(),
            'filter_options' => [
                'states' => IncidentSearchFilters::states(),
                'priorities' => IncidentSearchFilters::priorities(),
                'sorts' => IncidentSearchFilters::sorts(),
                'types' => $search->typeOptions($user, $event),
                'responders' => $search->responderOptions($user, $event),
                'max_per_page' => IncidentSearchFilters::MAX_PER_PAGE,
            ],
            // What an authoring form may put on an incident, as distinct from
            // what a reader may filter this list by (M16.20). The two differ:
            // `active` and `all` are ways of asking a question, not states an
            // incident can be in, and a type or responder that is not on an
            // incident yet is still a legitimate thing to add.
            'assignable' => [
                'statuses' => Incident::statuses(),
                'priorities' => Incident::priorityLabels(),
                'types' => $search->assignableTypeOptions($user, $event),
                'responders' => $search->assignableResponderOptions($user, $event),
            ],
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'total_pages' => max(1, $page->lastPage()),
                'has_more' => $page->hasMorePages(),
            ],
            'presets' => $presets->payloadFor($user, $event),
            'incidents' => $incidents,
        ]);
    }

    public function show(
        Request $request,
        Event $event,
        Incident $incident,
        IncidentReadAccess $access,
        IncidentPayloadSerializer $serializer,
        AuditService $audit,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canViewIncidents($user, $event)) {
            return $this->restrictedResponse();
        }

        $incident->loadMissing([
            'createdByUser',
            'incidentTypes',
            'incidentStaff.staff',
            'timelineEntries.actorUser',
        ]);

        $audit->recordForEntity(
            entity: $incident,
            action: 'incident.viewed',
            actorUser: $user,
            organizationId: $event->organization_id,
            eventId: $event->id,
            departmentId: $this->effectiveIncidentCommandDepartmentId($event),
            sourceContext: AuditEvent::SOURCE_API,
        );

        return response()->json([
            'event_id' => $event->id,
            'incident' => $serializer->payload($incident),
        ]);
    }

    private function restrictedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This page requires Incident Command access for the event configured IC department.',
        ], 403);
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
