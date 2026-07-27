<?php

namespace App\Http\Controllers\Node;

use App\Http\Controllers\Controller;
use App\Services\Node\NodeSyncException;
use App\Services\Node\NodeSyncRequest;
use App\Services\Node\NodeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Node-to-node sync exchange endpoint (technical spec 10.1, 10.2; data/API
 * 7.4, 13.6).
 *
 * This endpoint is node-to-node, not user-facing. There is no user session: the
 * calling node signs the exchange with its node private key and is verified
 * against the public key registered when the two nodes paired, which is why
 * request parsing and authentication both live in the domain services rather
 * than in framework validation — the signature covers the canonical payload
 * built from the parsed request, so the parse has to be the same one the
 * signature was made over.
 *
 * One request carries both directions: the caller's queued operations go up in
 * the body, and this node's queued operations come back in the response.
 */
class NodeSyncController extends Controller
{
    public function __construct(private readonly NodeSyncService $sync) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $response = $this->sync->exchange(
                NodeSyncRequest::fromArray($request->all()),
            );
        } catch (NodeSyncException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], $exception->status);
        }

        return response()->json($response->toArray());
    }
}
