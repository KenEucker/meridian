<?php

declare(strict_types=1);

namespace App\Http\Controllers\Session;

use App\Http\Controllers\Controller;
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
            return response()->json($sessions->resolve($user, $validated['event_id'] ?? null));
        } catch (SessionContextException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason_code' => $exception->reason,
                'node_locked_event_id' => $exception->lockedEventId,
            ], $exception->status);
        }
    }
}
