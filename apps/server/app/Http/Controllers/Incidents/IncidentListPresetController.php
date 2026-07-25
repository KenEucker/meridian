<?php

namespace App\Http\Controllers\Incidents;

use App\Exceptions\IncidentListPresetException;
use App\Exceptions\IncidentSearchException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Incidents\IncidentListPresetService;
use App\Services\Incidents\IncidentReadAccess;
use App\Services\Incidents\IncidentSearchFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved IMS incident list filter preset commands (M11.19).
 *
 * Data/API section 5.2. Presets are personal view state for one user on one
 * event, so they reuse the `incidents.view` gate rather than adding a
 * capability: if you may read the event's incident list, you may name your own
 * way of reading it. Presets are always addressed by owner, so one IC user can
 * never overwrite or delete another's.
 */
final class IncidentListPresetController extends Controller
{
    public function save(
        Request $request,
        IncidentReadAccess $access,
        IncidentListPresetService $presets,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'name' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);

        if (! $access->canViewIncidents($user, $event)) {
            return $this->restrictedResponse();
        }

        try {
            $filters = IncidentSearchFilters::fromQuery(
                is_array($validated['filters'] ?? null) ? $validated['filters'] : [],
            );

            $preset = $presets->save($user, $event, (string) $validated['name'], $filters);
        } catch (IncidentSearchException|IncidentListPresetException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'preset' => $presets->payload($preset),
            'presets' => $presets->payloadFor($user, $event),
        ], 201);
    }

    public function delete(
        Request $request,
        IncidentReadAccess $access,
        IncidentListPresetService $presets,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'preset_id' => ['required', 'uuid'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);

        if (! $access->canViewIncidents($user, $event)) {
            return $this->restrictedResponse();
        }

        try {
            $presets->delete($user, $event, (string) $validated['preset_id']);
        } catch (IncidentListPresetException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'presets' => $presets->payloadFor($user, $event),
        ]);
    }

    private function restrictedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This page requires Incident Command access for the event configured IC department.',
        ], 403);
    }
}
