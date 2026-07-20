<?php

namespace App\Http\Controllers\Incidents;

use App\Exceptions\IncidentAttachmentStrikeException;
use App\Exceptions\IncidentCreationException;
use App\Exceptions\IncidentFieldReportLinkException;
use App\Exceptions\IncidentLinkException;
use App\Exceptions\IncidentTimelineException;
use App\Exceptions\IncidentUpdateException;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentTimelineEntry;
use App\Services\Incidents\IncidentAttachmentStrikeService;
use App\Services\Incidents\IncidentCreationAccess;
use App\Services\Incidents\IncidentCreationService;
use App\Services\Incidents\IncidentFieldReportLinkService;
use App\Services\Incidents\IncidentLinkService;
use App\Services\Incidents\IncidentNoteAccess;
use App\Services\Incidents\IncidentTimelineService;
use App\Services\Incidents\IncidentUpdateAccess;
use App\Services\Incidents\IncidentUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online-only IMS command transport.
 *
 * Data/API section 5.2 documents POST /api/commands/create-incident and
 * POST /api/commands/append-incident-note. M11.7 adds
 * POST /api/commands/update-incident for online autosaved current-field edits.
 * M11.7B adds POST /api/commands/link-incident and
 * POST /api/commands/unlink-incident for same-event incident relationships.
 * M11.8 adds POST /api/commands/link-field-report and
 * POST /api/commands/unlink-field-report for Field Report relationships.
 * M11.9 adds POST /api/commands/strike-incident-attachment for preserved
 * incident attachment strike history.
 * Follow-up adds POST /api/commands/strike-incident-note for preserved
 * operational note strike history.
 * Technical spec 19.2 requires active server connection for incident mutations.
 */
final class IncidentCommandController extends Controller
{
    public function create(
        Request $request,
        IncidentCreationAccess $access,
        IncidentCreationService $incidents,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'title' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'priority_label' => ['nullable', 'string'],
            'started_at' => ['nullable', 'date'],
            'location_name' => ['nullable', 'string'],
            'location_address' => ['nullable', 'string'],
            'location_details' => ['nullable', 'string'],
            'camp_id' => ['nullable', 'uuid'],
            'map_location_id' => ['nullable', 'uuid'],
            'incident_type_names' => ['nullable', 'array'],
            'incident_type_names.*' => ['string'],
            'responder_staff_ids' => ['nullable', 'array'],
            'responder_staff_ids.*' => ['uuid'],
            'initial_field_update_fields' => ['nullable', 'array'],
            'initial_field_update_fields.*' => ['string'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);

        if (! $access->canCreateIncident($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may create incidents.',
            ], 403);
        }

        try {
            $incident = $incidents->create($validated, $user, sourceContext: AuditEvent::SOURCE_API);
        } catch (IncidentCreationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
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
            'incident_type_names' => $incident->incidentTypes()->pluck('name')->values()->all(),
            'responders' => $this->responderPayload($incident),
            'linked_incidents' => $this->linkedIncidentPayload($incident),
            'created_by_user_id' => $incident->created_by_user_id,
            'created_at' => optional($incident->created_at)?->toIso8601String(),
        ], 201);
    }

    public function update(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentUpdateService $incidents,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'incident_id' => ['required', 'uuid', 'exists:incidents,id'],
            'title' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', 'string'],
            'priority_label' => ['sometimes', 'required', 'string'],
            'started_at' => ['sometimes', 'required', 'date'],
            'location_name' => ['sometimes', 'nullable', 'string'],
            'location_address' => ['sometimes', 'nullable', 'string'],
            'location_details' => ['sometimes', 'nullable', 'string'],
            'camp_id' => ['sometimes', 'nullable', 'uuid'],
            'map_location_id' => ['sometimes', 'nullable', 'uuid'],
            'incident_type_names' => ['sometimes', 'array'],
            'incident_type_names.*' => ['string'],
            'responder_staff_ids' => ['sometimes', 'array'],
            'responder_staff_ids.*' => ['uuid'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $incident = Incident::query()->findOrFail((string) $validated['incident_id']);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canUpdateIncident($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may edit incidents.',
            ], 403);
        }

        unset($validated['event_id'], $validated['incident_id']);

        try {
            $incident = $incidents->update(
                incident: $incident,
                actor: $user,
                attributes: $validated,
                sourceContext: AuditEvent::SOURCE_API,
            );
        } catch (IncidentUpdateException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
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
            'incident_type_names' => $incident->incidentTypes()->pluck('name')->values()->all(),
            'responders' => $this->responderPayload($incident),
            'linked_incidents' => $this->linkedIncidentPayload($incident),
            'created_by_user_id' => $incident->created_by_user_id,
            'created_at' => optional($incident->created_at)?->toIso8601String(),
            'updated_at' => optional($incident->updated_at)?->toIso8601String(),
            'closed_at' => optional($incident->closed_at)?->toIso8601String(),
        ]);
    }

    public function linkIncident(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentLinkService $links,
    ): JsonResponse {
        return $this->mutateIncidentLink($request, $access, $links, unlink: false);
    }

    public function unlinkIncident(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentLinkService $links,
    ): JsonResponse {
        return $this->mutateIncidentLink($request, $access, $links, unlink: true);
    }

    public function linkFieldReport(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentFieldReportLinkService $links,
    ): JsonResponse {
        return $this->mutateFieldReportLink($request, $access, $links, unlink: false);
    }

    public function unlinkFieldReport(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentFieldReportLinkService $links,
    ): JsonResponse {
        return $this->mutateFieldReportLink($request, $access, $links, unlink: true);
    }

    public function strikeAttachment(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentAttachmentStrikeService $attachments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'incident_id' => ['required', 'uuid', 'exists:incidents,id'],
            'attachment_id' => ['required', 'uuid', 'exists:attachments,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Incident attachment strike reason is required.',
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $incident = Incident::query()->findOrFail((string) $validated['incident_id']);
        $attachment = Attachment::query()->findOrFail((string) $validated['attachment_id']);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canUpdateIncident($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may strike incident attachments.',
            ], 403);
        }

        try {
            $attachment = $attachments->strike(
                incident: $incident,
                attachment: $attachment,
                actor: $user,
                reason: (string) $validated['reason'],
                sourceContext: AuditEvent::SOURCE_API,
            );
        } catch (IncidentAttachmentStrikeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => $attachment->id,
            'incident_id' => $attachment->attachable_id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size,
            'created_at' => optional($attachment->created_at)?->toIso8601String(),
            'stricken_at' => optional($attachment->stricken_at)?->toIso8601String(),
            'deleted_at' => optional($attachment->deleted_at)?->toIso8601String(),
        ]);
    }

    public function appendNote(
        Request $request,
        IncidentNoteAccess $access,
        IncidentTimelineService $timeline,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'incident_id' => ['required', 'uuid', 'exists:incidents,id'],
            'body' => ['required', 'string'],
        ], [
            'body.required' => 'Incident note body is required.',
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $incident = Incident::query()->findOrFail((string) $validated['incident_id']);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canAppendNote($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may add incident notes.',
            ], 403);
        }

        try {
            $entry = $timeline->appendNote(
                incident: $incident,
                actor: $user,
                body: (string) $validated['body'],
                sourceContext: AuditEvent::SOURCE_API,
            );
        } catch (IncidentTimelineException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
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
        ], 201);
    }

    public function strikeNote(
        Request $request,
        IncidentNoteAccess $access,
        IncidentTimelineService $timeline,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'incident_id' => ['required', 'uuid', 'exists:incidents,id'],
            'timeline_entry_id' => ['required', 'uuid', 'exists:incident_timeline_entries,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Incident note strike reason is required.',
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $incident = Incident::query()->findOrFail((string) $validated['incident_id']);
        $entry = IncidentTimelineEntry::query()->findOrFail((string) $validated['timeline_entry_id']);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canAppendNote($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may strike incident notes.',
            ], 403);
        }

        try {
            $entry = $timeline->strikeNote(
                incident: $incident,
                entry: $entry,
                actor: $user,
                reason: (string) $validated['reason'],
                sourceContext: AuditEvent::SOURCE_API,
            );
        } catch (IncidentTimelineException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->timelineEntryResponse($entry));
    }

    /**
     * @return list<array{staff_id: string, display_name: string, relationship_label: string}>
     */
    private function responderPayload(Incident $incident): array
    {
        return $incident->incidentStaff()
            ->with('staff')
            ->get()
            ->map(fn ($staff): array => [
                'staff_id' => $staff->staff_id,
                'display_name' => $staff->staff?->preferred_name
                    ?? $staff->staff?->handle
                    ?? $staff->staff?->legal_name
                    ?? 'Unknown responder',
                'relationship_label' => $staff->relationship_label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryResponse(IncidentTimelineEntry $entry): array
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

    private function mutateIncidentLink(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentLinkService $links,
        bool $unlink,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'incident_id' => ['required', 'uuid', 'exists:incidents,id'],
            'target_incident_id' => ['required', 'uuid', 'exists:incidents,id'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $incident = Incident::query()->findOrFail((string) $validated['incident_id']);
        $target = Incident::query()->findOrFail((string) $validated['target_incident_id']);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canUpdateIncident($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may link incidents.',
            ], 403);
        }

        try {
            $link = $unlink
                ? $links->unlink($incident, $target, $user, sourceContext: AuditEvent::SOURCE_API)
                : $links->link($incident, $target, $user, sourceContext: AuditEvent::SOURCE_API);
        } catch (IncidentLinkException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => $link->id,
            'source_incident_id' => $link->source_incident_id,
            'target_incident_id' => $link->target_incident_id,
            'link_type' => $link->link_type,
            'created_by_user_id' => $link->created_by_user_id,
            'created_at' => optional($link->created_at)?->toIso8601String(),
            'unlinked_by_user_id' => $link->unlinked_by_user_id,
            'unlinked_at' => optional($link->unlinked_at)?->toIso8601String(),
            'linked_incidents' => $this->linkedIncidentPayload($incident->refresh()),
        ], $unlink ? 200 : 201);
    }

    private function mutateFieldReportLink(
        Request $request,
        IncidentUpdateAccess $access,
        IncidentFieldReportLinkService $links,
        bool $unlink,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'incident_id' => ['required', 'uuid', 'exists:incidents,id'],
            'field_report_id' => ['required', 'uuid', 'exists:field_reports,id'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $incident = Incident::query()->findOrFail((string) $validated['incident_id']);
        $fieldReport = FieldReport::query()->findOrFail((string) $validated['field_report_id']);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $access->canUpdateIncident($user, $event)) {
            return response()->json([
                'message' => 'Only IC operators and IC leads for this event may link Field Reports.',
            ], 403);
        }

        try {
            $link = $unlink
                ? $links->unlink($incident, $fieldReport, $user, sourceContext: AuditEvent::SOURCE_API)
                : $links->link($incident, $fieldReport, $user, sourceContext: AuditEvent::SOURCE_API);
        } catch (IncidentFieldReportLinkException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->fieldReportLinkPayload($link), $unlink ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldReportLinkPayload(IncidentFieldReport $link): array
    {
        return [
            'id' => $link->id,
            'incident_id' => $link->incident_id,
            'field_report_id' => $link->field_report_id,
            'linked_by_user_id' => $link->linked_by_user_id,
            'linked_at' => optional($link->linked_at)?->toIso8601String(),
            'unlinked_by_user_id' => $link->unlinked_by_user_id,
            'unlinked_at' => optional($link->unlinked_at)?->toIso8601String(),
            'stricken_reason' => $link->stricken_reason,
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
}
