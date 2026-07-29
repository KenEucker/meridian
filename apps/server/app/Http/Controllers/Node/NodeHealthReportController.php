<?php

namespace App\Http\Controllers\Node;

use App\Http\Controllers\Controller;
use App\Services\NodeHealth\NodeHealthException;
use App\Services\NodeHealth\NodeHealthReportPayload;
use App\Services\NodeHealth\NodeHealthReportReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Node health report endpoint (technical spec 22A.11; SYS-037, SYS-038).
 *
 * Node-to-node, not user-facing: the reporting node signs the report with its
 * node private key and is verified against the public key learned at pairing,
 * exactly like the sync exchange next door. There is no user session and no
 * body a report could smuggle unverified content in — everything stored comes
 * from the signed canonical payload.
 */
class NodeHealthReportController extends Controller
{
    public function __construct(private readonly NodeHealthReportReceiver $receiver) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $report = $this->receiver->receive(
                NodeHealthReportPayload::fromArray($request->all()),
            );
        } catch (NodeHealthException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], $exception->status);
        }

        return response()->json([
            'status' => 'stored',
            'report_uuid' => $report->report_uuid,
        ]);
    }
}
