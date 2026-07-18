<?php

namespace App\Http\Controllers\FieldReports;

use App\Exceptions\FieldReportAcceptanceException;
use App\Http\Controllers\Controller;
use App\Services\FieldReports\FieldReportAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Field Report text command transport (M9.8 local/upload prerequisite).
 *
 * Data/API section 5.2 documents POST /api/commands/submit-field-report.
 * Photo upload requires the accepted text record to exist first.
 */
class FieldReportCommandController extends Controller
{
    public function submit(
        Request $request,
        FieldReportAcceptanceService $acceptance,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'id' => ['required', 'uuid'],
            'event_id' => ['required', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'team_id' => ['nullable', 'uuid'],
            'staff_id' => ['required', 'uuid'],
            'temporary_local_number' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
            'device_submitted_at' => ['required', 'date'],
            'origin_device_id' => ['required', 'uuid'],
            'origin_node_id' => ['required', 'uuid'],
        ]);

        if ((string) $validated['staff_id'] === '') {
            return response()->json(['message' => 'staff_id is required.'], 422);
        }

        try {
            $report = $acceptance->accept([
                ...$validated,
                'submitted_by_user_id' => (string) $user->getKey(),
            ]);
        } catch (FieldReportAcceptanceException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'id' => $report->id,
            'event_id' => $report->event_id,
            'fra_number' => $report->fra_number,
            'temporary_local_number' => $report->temporary_local_number,
            'title' => $report->title,
            'body' => $report->body,
            'sync_status' => $report->sync_status,
            'server_received_at' => optional($report->server_received_at)?->toIso8601String(),
            'device_submitted_at' => optional($report->device_submitted_at)?->toIso8601String(),
        ], 201);
    }
}
