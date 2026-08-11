<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\SharedWorkstationSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/me/workstation-sessions` — the shared workstations this caller has
 * signed in at (M18.71; AUTH-030; technical spec 13.3; UI contract 12.3).
 *
 * A fact about the caller, like `GET /api/me/profile` beside it, so it needs no
 * authority beyond holding a credential: a person may always read where their
 * own login has been. There is no subject parameter, which is the whole of how
 * that is enforced — nobody can point this at anybody else's history, and no
 * role changes that.
 *
 * The rows are the sessions themselves rather than a summary of them. Somebody
 * checking this is answering one of two questions — "am I still signed in
 * somewhere I walked away from", and "was that me" — and both need the
 * workstation, the event, and when it started and stopped.
 *
 * Whether a session is still live is computed here rather than stored, on the
 * same reasoning {@see SharedWorkstationSession::isActive()} is built on: a
 * workstation whose user walked away is over five minutes later even though no
 * request arrived to record it. A client that read `ended_at` alone would tell
 * somebody they are still signed in at a machine that timed out an hour ago.
 */
final class MyWorkstationSessionController extends Controller
{
    /**
     * How far back the history goes.
     *
     * Enough to cover an event rather than everything a login has ever done. The
     * question this page answers is about the last few days at most, and an
     * unbounded read on a busy account is a page that gets slower every event.
     */
    private const LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $sessions = SharedWorkstationSession::query()
            ->where('user_id', $user->getKey())
            ->with(['sharedWorkstation', 'event'])
            ->orderByDesc('started_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'sessions' => $sessions
                ->map(fn (SharedWorkstationSession $session): array => [
                    'id' => (string) $session->getKey(),
                    /*
                     * The workstation's name, which a revoked machine keeps —
                     * retiring one sets `revoked_at` and the row stays, and
                     * `shared_workstation_sessions` restricts deletion besides,
                     * so a workstation with history cannot go away underneath
                     * this. That is the answer "was that me" needs: naming the
                     * kiosk beats reporting that it is no longer in service.
                     *
                     * Null-safe anyway, because a read that renders a whole
                     * history is not the place to discover a broken foreign key.
                     */
                    'workstation_name' => $session->sharedWorkstation?->name,
                    'event_name' => $session->event?->name,
                    'started_at' => $session->started_at?->toIso8601String(),
                    'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                    'ended_at' => $session->ended_at?->toIso8601String(),
                    'ended_reason' => $session->ended_reason,
                    'active' => $session->isActive(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
