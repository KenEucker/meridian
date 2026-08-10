<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSignInRequest;
use App\Services\Auth\EstablishedSharedWorkstationSession;
use App\Services\Auth\SharedWorkstationSignInRequestException;
use App\Services\Auth\SharedWorkstationSignInRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workstation's two ends of the scan path: open a sign-in request, and
 * collect its grant (M18.59; AUTH-032, AUTH-035, AUTH-037; technical spec
 * 13.4; data/API 12.4A).
 *
 * Both routes are unauthenticated and keyed by the workstation id the locked
 * machine already holds — the same reasoning as the pinned-context read,
 * because a locked workstation has no credential to ask with. What makes that
 * safe here, where the M18.32 precedent only disclosed a pinned context, is
 * the request-id/pickup-secret split: opening hands the *machine* a secret it
 * never displays, the QR carries only public identifiers, and collection
 * requires the secret. A caller who can reach these routes but did not open
 * the request can neither collect it nor learn whether it was granted.
 *
 * The grant itself is the authenticated half and lives on its own route,
 * behind `auth:sanctum`, in {@see \App\Http\Controllers\Auth\WorkstationSignInGrantController}.
 */
final class WorkstationSignInRequestController extends Controller
{
    public function __construct(private readonly SharedWorkstationSignInRequestService $requests) {}

    /**
     * Open a request and hand the opener everything the QR needs: the request
     * id, the workstation, the issuing node's identity — and, separately, the
     * pickup secret only this response ever carries.
     */
    public function store(SharedWorkstation $sharedWorkstation): JsonResponse
    {
        abort_unless($sharedWorkstation->isTrusted(), 404);

        try {
            $opened = $this->requests->open($sharedWorkstation);
        } catch (SharedWorkstationSignInRequestException $exception) {
            return $this->refusal($exception);
        }

        $node = $this->requests->localNode();

        return response()->json([
            'sign_in_request' => [
                'id' => $opened->record->getKey(),
                'purpose' => $opened->record->purpose,
                'expires_at' => $opened->record->expires_at?->toIso8601String(),
            ],
            // The one moment the pickup secret exists outside the machine that
            // will poll with it. Never displayed, logged, or audited (AUTH-037).
            'pickup_secret' => $opened->pickupSecret,
            'shared_workstation' => [
                'id' => $sharedWorkstation->getKey(),
                'name' => $sharedWorkstation->name,
                'short_code' => $sharedWorkstation->short_code,
            ],
            // The issuing node, named in the QR so a phone pointed at a
            // different node refuses before issuing anything (AUTH-034).
            'node' => $node instanceof Node ? [
                'id' => $node->getKey(),
                'name' => $node->node_name,
            ] : null,
            'event_id' => $opened->record->event_id,
        ], 201);
    }

    /**
     * Poll for the grant with the pickup secret.
     *
     * Pending is a 200 with no session, because a locked Kiosk asking "has
     * anybody scanned me yet" several times a minute is the mechanism working,
     * not an error. Everything wrong about the ask itself refuses.
     */
    public function collect(
        Request $request,
        SharedWorkstation $sharedWorkstation,
        SharedWorkstationSignInRequest $signInRequest,
    ): JsonResponse {
        abort_unless($sharedWorkstation->isTrusted(), 404);

        $validated = $request->validate([
            'pickup_secret' => ['required', 'string', 'max:128'],
        ]);

        try {
            $established = $this->requests->collect($sharedWorkstation, $signInRequest, (string) $validated['pickup_secret']);
        } catch (SharedWorkstationSignInRequestException $exception) {
            return $this->refusal($exception);
        }

        if (! $established instanceof EstablishedSharedWorkstationSession) {
            return response()->json(['status' => 'pending']);
        }

        $session = $established->record;
        $user = $session->user()->first();

        return response()->json([
            'status' => 'collected',
            // The session key, exactly as a typed entry returns it: once, to
            // the workstation, held in memory only (AUTH-035; technical spec
            // 13.3).
            'session_key' => $established->sessionKey,
            'session' => [
                'id' => $session->getKey(),
                'started_at' => $session->started_at?->toIso8601String(),
                'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                'expires_at' => $session->expiresAt()->toIso8601String(),
                'inactivity_timeout_seconds' => \App\Models\SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES * 60,
                'reauthenticated_at' => $session->reauthenticated_at?->toIso8601String(),
            ],
            'user' => $user === null ? null : [
                'id' => $user->getKey(),
                'name' => $user->name,
            ],
            'shared_workstation' => [
                'id' => $sharedWorkstation->getKey(),
                'name' => $sharedWorkstation->name,
                'organization_id' => $sharedWorkstation->organization_id,
                'department_id' => $sharedWorkstation->department_id,
            ],
            'event_id' => $session->event_id,
        ], 201);
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
