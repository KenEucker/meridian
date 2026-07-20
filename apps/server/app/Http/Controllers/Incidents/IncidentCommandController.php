<?php

namespace App\Http\Controllers\Incidents;

use App\Exceptions\IncidentCreationException;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Services\Incidents\IncidentCreationAccess;
use App\Services\Incidents\IncidentCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online-only IMS command transport.
 *
 * Data/API section 5.2 documents POST /api/commands/create-incident, and
 * technical spec 19.2 requires active server connection for incident creation.
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
}
