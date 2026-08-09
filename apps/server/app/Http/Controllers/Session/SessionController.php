<?php

declare(strict_types=1);

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\User;
use App\Services\Session\SessionContextException;
use App\Services\Session\SessionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/me` — the session a client establishes after login (CLIENT-001
 * through CLIENT-003; technical spec 11A.2; data/API 5.5).
 *
 * The response says who the caller is, the effective role codes they hold, the
 * capability codes those roles carry, and the organizations, events, departments,
 * and teams they are associated with. It says nothing about navigation: no screen
 * list, no menu structure, no precomputed surface availability. A client derives
 * what to render from the capabilities, and every endpoint still enforces its own
 * authorization regardless of what the client rendered (CLIENT-006).
 *
 * There is no parameter naming whose session to return. The endpoint resolves the
 * caller's own associations and no one else's, and the optional `event_id` narrows
 * the context to one of the caller's own events rather than widening it.
 *
 * The response also reports whether the device this request arrived on is
 * trusted for this user (AUTH-024; technical spec 12.2, 14). The device is taken
 * from the caller's own token binding rather than from anything the client
 * sends, so it is the same "no parameter for whose" rule applied to hardware.
 */
final class SessionController extends Controller
{
    public function show(Request $request, SessionResolver $sessions): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            // Which of the caller's events to resolve roles at. Omitted, the
            // node's lock decides (technical spec 11A.3).
            'event_id' => ['sometimes', 'uuid'],
        ]);

        try {
            return response()->json($sessions->resolve(
                $user,
                $validated['event_id'] ?? null,
                $this->callingDevice($request),
            ));
        } catch (SessionContextException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason_code' => $exception->reason,
                'node_locked_event_id' => $exception->lockedEventId,
            ], $exception->status);
        }
    }

    /**
     * The device this request arrived on, when the credential names one.
     *
     * A Meridian bearer token is bound to a device (AUTH-021), so the token the
     * caller presented is the only place a device identity could honestly come
     * from — a client-supplied one would let a device ask about another. A
     * shared-workstation session key names no device and resolves to null, which
     * is what the readiness checklist reads as "this session names no device"
     * rather than as a device that failed a check.
     */
    private function callingDevice(Request $request): ?Device
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof ApiToken || $token->device_id === null) {
            return null;
        }

        return Device::query()->find($token->device_id);
    }
}
