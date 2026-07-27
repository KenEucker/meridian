<?php

namespace App\Http\Controllers\Node;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\Node\NodePairingException;
use App\Services\Node\NodePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Central node endpoint that redeems a one-time pairing token (technical spec
 * 7.3, 7.4).
 *
 * This endpoint is node-to-node, not user-facing: the one-time pairing token
 * issued by central in God mode is the only credential, so the route carries no
 * session or user authentication. Rate limiting is applied on the route.
 */
class NodePairingController extends Controller
{
    public function __construct(private readonly NodePairingService $pairing) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pairing_token' => ['required', 'string', 'max:255'],
            'node_name' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i',
            ],
            'node_role' => ['required', Rule::in(Node::PAIRABLE_ROLES)],
            'public_key' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $result = $this->pairing->redeem(
                plaintextToken: $validated['pairing_token'],
                nodeName: $validated['node_name'],
                nodeRole: $validated['node_role'],
                publicKey: $validated['public_key'],
            );
        } catch (NodePairingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
            ], $exception->status);
        }

        return response()->json(
            $result->toResponseArray(),
            $result->replayed ? 200 : 201,
        );
    }
}
