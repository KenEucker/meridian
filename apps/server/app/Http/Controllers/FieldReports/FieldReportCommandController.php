<?php

namespace App\Http\Controllers\FieldReports;

use App\Exceptions\FieldReportAcceptanceException;
use App\Http\Controllers\Controller;
use App\Services\FieldReports\FieldReportAcceptanceService;
use App\Services\Node\NodeSetupService;
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
    public function __construct(private readonly NodeSetupService $nodes) {}

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
            /*
             * Optional, on the same reasoning as the attendance commands
             * (M16.21). A browser has no way to learn a node id — nothing
             * publishes one, deliberately — so the node that received the
             * command records itself as the origin. A caller replaying a report
             * that originated somewhere else still names that node, which is why
             * the field survives rather than being dropped.
             */
            'origin_node_id' => ['nullable', 'uuid'],
        ]);

        if ((string) $validated['staff_id'] === '') {
            return response()->json(['message' => 'staff_id is required.'], 422);
        }

        $originNodeId = $this->originNodeId($validated);

        if ($originNodeId === null) {
            return response()->json([
                'message' => 'This node is not configured, so a Field Report cannot record where it came from.',
            ], 422);
        }

        try {
            $report = $acceptance->accept([
                ...$validated,
                'origin_node_id' => $originNodeId,
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

    /**
     * The node this Field Report originated at.
     *
     * Named by the caller when it is replaying one from somewhere else;
     * otherwise this install's own node, because a report posted to this node
     * originated here. Null when neither answers, which the caller is told about
     * rather than having a guess recorded as provenance.
     *
     * @param  array<string, mixed>  $validated
     */
    private function originNodeId(array $validated): ?string
    {
        if (isset($validated['origin_node_id'])) {
            return (string) $validated['origin_node_id'];
        }

        return $this->nodes->activeNode()?->getKey();
    }
}
