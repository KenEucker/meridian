<?php

namespace App\Http\Controllers\Incidents;

use App\Exceptions\IncidentCreationException;
use App\Exceptions\IncidentTimelineException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Services\Incidents\IncidentCreationAccess;
use App\Services\Incidents\IncidentCreationService;
use App\Services\Incidents\IncidentNoteAccess;
use App\Services\Incidents\IncidentTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online-only IMS command transport.
 *
 * Data/API section 5.2 documents POST /api/commands/create-incident and
 * POST /api/commands/append-incident-note. Technical spec 19.2 requires active
 * server connection for incident mutations.
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
            'title' => ['required', 'string'],
            'status' => ['nullable', 'string'],
            'started_at' => ['nullable', 'date'],
            'location_name' => ['nullable', 'string'],
            'location_address' => ['nullable', 'string'],
            'location_details' => ['nullable', 'string'],
            'camp_id' => ['nullable', 'uuid'],
            'map_location_id' => ['nullable', 'uuid'],
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
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
            'created_by_user_id' => $incident->created_by_user_id,
            'created_at' => optional($incident->created_at)?->toIso8601String(),
        ], 201);
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
}
