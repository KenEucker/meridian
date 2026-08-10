<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use App\Services\Auth\SharedWorkstationLoginException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service shared-workstation login code generation (AUTH-026, AUTH-027,
 * AUTH-028; technical spec 13.2; data/API 12.4).
 *
 * `POST /api/auth/shared-workstation-login-code` is the on-site login path: a
 * staff member standing at a kiosk with no internet, no mail delivery, and no
 * technician available asks the node for a code on the phone that still holds a
 * session against it, and types the code into the workstation in front of them.
 *
 * The endpoint needs only the node that will accept the code (AUTH-027) — no
 * central reachability, no mail, no out-of-band channel of any kind — which is
 * why it does nothing but read the workstation and write the code.
 *
 * The request names no user. A code is for whoever holds the session that asked
 * for it (AUTH-028), so there is no field a caller could put somebody else's
 * identifier in; a request that names another user is refused rather than
 * quietly reinterpreted, because a client that thinks it can do this should be
 * told it cannot. God mode generating a code for another user is the console
 * screen `platform.shared-workstation-login-codes`.
 */
class SharedWorkstationLoginCodeController extends Controller
{
    public function __construct(private readonly SharedWorkstationLoginCodeService $loginCodes) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Optional since M18.58: a code issued with no workstation binds to
            // the first trusted workstation that redeems it (AUTH-031).
            'shared_workstation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The caller's current event context, which scopes an unbound code.
            // Ignored when a workstation is named: its pinned event wins.
            'event_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Accepted only so it can be refused: see the class docblock.
            'user_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (isset($validated['user_id']) && (string) $validated['user_id'] !== (string) $user->getKey()) {
            return $this->refusal(SharedWorkstationLoginException::selfServiceScope());
        }

        $workstation = null;
        $event = null;

        if (($validated['shared_workstation_id'] ?? null) !== null) {
            $workstation = SharedWorkstation::query()->find($validated['shared_workstation_id']);

            if (! $workstation instanceof SharedWorkstation) {
                return $this->refusal(SharedWorkstationLoginException::workstationUnknown());
            }
        } elseif (($validated['event_id'] ?? null) !== null) {
            $event = Event::query()->find($validated['event_id']);

            if (! $event instanceof Event) {
                return $this->refusal(SharedWorkstationLoginException::eventUnknown());
            }
        }

        try {
            $issued = $this->loginCodes->generateForSelf($user, $workstation, $this->sessionDevice($request), $event);
        } catch (SharedWorkstationLoginException $exception) {
            return $this->refusal($exception);
        }

        return response()->json([
            // The one moment the code exists outside the person who will type it.
            // It is not stored, logged, audited, or retrievable again, and it is
            // shown on the requesting device rather than mailed, because the
            // point of the mechanism is that no mail may be leaving this node.
            'code' => $issued->formattedCode(),
            'expires_at' => $issued->record->expires_at?->toIso8601String(),
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
            ],
            // Null for an unbound code: it binds to the first trusted
            // workstation that redeems it (AUTH-031), and the client says so.
            'shared_workstation' => $workstation instanceof SharedWorkstation ? [
                'id' => $workstation->getKey(),
                'name' => $workstation->name,
            ] : null,
            // Echoed so the client can show which event the code will sign the
            // person in to. For a targeted code it comes from the workstation's
            // pinned Kiosk context (technical spec 13.1), never from the request.
            'event_id' => $issued->record->event_id,
        ], 201);
    }

    /**
     * The device the calling session is bound to, recorded as the actor device on
     * the generation audit entry.
     *
     * Every issued token is device-bound (AUTH-021), so this is what makes the
     * audit trail say which of a person's devices generated a code — the detail
     * that matters when the question later is whether the person did it.
     */
    private function sessionDevice(Request $request): ?Device
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof ApiToken ? $token->device : null;
    }

    private function refusal(SharedWorkstationLoginException $exception): JsonResponse
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
