<?php

namespace App\Http\Controllers\Incidents;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Services\Audit\AuditService;
use App\Services\Incidents\IncidentReadAccess;
use App\Services\NameReferences\NameReferenceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restricted IMS incident read transport (M11.5).
 *
 * Data/API section 5.1 documents resource reads under
 * GET /api/events/{event}/incidents. Section 6.5 requires incident rows to be
 * returned only to IC-authorized users; UI hiding alone is insufficient.
 */
final class IncidentReadController extends Controller
{
    public function index(
        Request $request,
        Event $event,
        IncidentReadAccess $access,
        NameReferenceSearchService $nameReferences,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canViewIncidents($user, $event)) {
            return $this->restrictedResponse();
        }

        $search = $request->query('search');
        $incidents = is_string($search) && trim($search) !== ''
            ? $nameReferences->searchIncidents($user, $search, $event)
            : Incident::query()
                ->with(['createdByUser', 'incidentTypes', 'incidentStaff.staff'])
                ->forEvent($event)
                ->orderByDesc('updated_at')
                ->orderByDesc('started_at')
                ->get();

        $incidents = $incidents
            ->map(fn (Incident $incident): array => $this->incidentPayload($incident, $nameReferences))
            ->values();

        return response()->json([
            'event_id' => $event->id,
            'incidents' => $incidents,
        ]);
    }

    public function show(
        Request $request,
        Event $event,
        Incident $incident,
        IncidentReadAccess $access,
        AuditService $audit,
        NameReferenceSearchService $nameReferences,
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
            'incident' => $this->incidentPayload($incident, $nameReferences),
        ]);
    }

    private function restrictedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This page requires Incident Command access for the event configured IC department.',
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentPayload(Incident $incident, NameReferenceSearchService $nameReferences): array
    {
        return [
            'id' => $incident->id,
            'event_id' => $incident->event_id,
            'incident_number' => $incident->incident_number,
            'status' => $incident->status,
            'priority_label' => $incident->priority_label,
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
            'incident_type_names' => $incident->incidentTypes->pluck('name')->values()->all(),
            'responders' => $incident->incidentStaff
                ->map(fn (IncidentStaff $staff): array => [
                    'staff_id' => $staff->staff_id,
                    'display_name' => $staff->staff?->preferred_name
                        ?? $staff->staff?->handle
                        ?? $staff->staff?->legal_name
                        ?? 'Unknown responder',
                    'relationship_label' => $staff->relationship_label,
                ])
                ->values()
                ->all(),
            'linked_incidents' => $this->linkedIncidentPayload($incident),
            'attached_field_reports' => $this->attachedFieldReportPayload($incident),
            'created_by_user_id' => $incident->created_by_user_id,
            'created_by_name' => $incident->createdByUser?->name,
            'created_at' => optional($incident->created_at)?->toIso8601String(),
            'updated_at' => optional($incident->updated_at)?->toIso8601String(),
            'closed_at' => optional($incident->closed_at)?->toIso8601String(),
            'name_reference_chips' => $nameReferences->incidentChips($incident),
            'timeline_entries' => $incident->relationLoaded('timelineEntries')
                ? $incident->timelineEntries->map(
                    fn (IncidentTimelineEntry $entry): array => $this->timelineEntryPayload($entry),
                )->values()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryPayload(IncidentTimelineEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'incident_id' => $entry->incident_id,
            'actor_user_id' => $entry->actor_user_id,
            'actor_name' => $entry->actorUser?->name,
            'entry_type' => $entry->entry_type,
            'body' => $entry->body,
            'previous_value' => $entry->previous_value,
            'new_value' => $entry->new_value,
            'reason' => $entry->reason,
            'created_at' => optional($entry->created_at)?->toIso8601String(),
            'stricken_at' => optional($entry->stricken_at)?->toIso8601String(),
            'stricken_reason' => $entry->stricken_reason,
        ];
    }

    /**
     * @return list<array{id: string, incident_number: string, title: string, status: string}>
     */
    private function linkedIncidentPayload(Incident $incident): array
    {
        return IncidentLink::query()
            ->with(['sourceIncident', 'targetIncident'])
            ->whereNull('unlinked_at')
            ->where(function ($query) use ($incident): void {
                $query
                    ->where('source_incident_id', $incident->id)
                    ->orWhere('target_incident_id', $incident->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (IncidentLink $link) use ($incident): array {
                $linkedIncident = (string) $link->source_incident_id === (string) $incident->id
                    ? $link->targetIncident
                    : $link->sourceIncident;

                return [
                    'id' => $linkedIncident->id,
                    'incident_number' => $linkedIncident->incident_number,
                    'title' => $linkedIncident->title,
                    'status' => $linkedIncident->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, field_report_id: string, incident_field_report_id: string, display_number: string, title: string, author_name: string, body: string, linked_at: ?string}>
     */
    private function attachedFieldReportPayload(Incident $incident): array
    {
        return IncidentFieldReport::query()
            ->with(['fieldReport.staff', 'fieldReport.submittedByUser'])
            ->where('incident_id', $incident->id)
            ->whereNull('unlinked_at')
            ->orderBy('linked_at')
            ->orderBy('id')
            ->get()
            ->map(function (IncidentFieldReport $link): array {
                $fieldReport = $link->fieldReport;

                return [
                    'id' => $fieldReport->id,
                    'field_report_id' => $fieldReport->id,
                    'incident_field_report_id' => $link->id,
                    'display_number' => $fieldReport->fra_number
                        ?? $fieldReport->temporary_local_number
                        ?? 'Field Report',
                    'title' => $fieldReport->title,
                    'author_name' => $fieldReport->staff?->preferred_name
                        ?? $fieldReport->staff?->legal_name
                        ?? $fieldReport->submittedByUser?->name
                        ?? 'Unknown author',
                    'body' => $fieldReport->body,
                    'linked_at' => optional($link->linked_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
