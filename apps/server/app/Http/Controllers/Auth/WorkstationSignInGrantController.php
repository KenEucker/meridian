<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSignInRequest;
use App\Models\User;
use App\Services\Auth\SharedWorkstationSignInRequestException;
use App\Services\Auth\SharedWorkstationSignInRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The phone's end of the scan path: grant a workstation sign-in request for
 * the signed-in user (M18.59; AUTH-033, AUTH-034; technical spec 13.4;
 * data/API 12.4A).
 *
 * Behind `auth:sanctum`, because holding a session on the granting device is
 * the whole authority for the request. The grant carries no user field — a
 * request is granted for whoever holds the session that granted it, so there
 * is structurally nothing to put another person's identifier in (AUTH-033).
 * One is accepted only so it can be refused, mirroring the login-code
 * endpoint: a client that believes it can grant for somebody else should be
 * told it cannot.
 *
 * `node_id` is the node identity the device read out of the QR. The domain
 * refuses a mismatch with both nodes named (AUTH-034), catching the phone
 * pointed at central while the workstation lives on an on-site node.
 */
final class WorkstationSignInGrantController extends Controller
{
    public function __construct(private readonly SharedWorkstationSignInRequestService $requests) {}

    public function store(Request $request, SharedWorkstationSignInRequest $signInRequest): JsonResponse
    {
        $validated = $request->validate([
            'node_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Accepted only so it can be refused: see the class docblock.
            'user_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (isset($validated['user_id']) && (string) $validated['user_id'] !== (string) $user->getKey()) {
            return $this->refusal(SharedWorkstationSignInRequestException::grantScope());
        }

        try {
            $granted = $this->requests->grant($signInRequest, $user, $validated['node_id'] ?? null);
        } catch (SharedWorkstationSignInRequestException $exception) {
            return $this->refusal($exception);
        }

        $workstation = $granted->sharedWorkstation()->first();

        return response()->json([
            'granted' => true,
            'sign_in_request' => [
                'id' => $granted->getKey(),
                'purpose' => $granted->purpose,
                'granted_at' => $granted->granted_at?->toIso8601String(),
            ],
            // So the confirmation screen can say which machine just signed the
            // person in, in its own words.
            'shared_workstation' => $workstation instanceof SharedWorkstation ? [
                'id' => $workstation->getKey(),
                'name' => $workstation->name,
            ] : null,
            'event_id' => $granted->event_id,
        ]);
    }

    private function refusal(SharedWorkstationSignInRequestException $exception): JsonResponse
    {
        $response = response()->json([
            'message' => $exception->getMessage(),
            'reason' => $exception->reason,
        ], $exception->status);

        return $exception->retryAfterSeconds === null
            ? $response
            : $response->header('Retry-After', (string) $exception->retryAfterSeconds);
    }
}
