<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\User;
use App\Services\Auth\SharedWorkstationLoginException;
use App\Services\Auth\SharedWorkstationSessionKey;
use App\Services\Auth\SharedWorkstationSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared-workstation sessions (AUTH-030; technical spec 13.3; data/API 12.3).
 *
 * The three things a Kiosk does with a session: start one by entering a code,
 * read the one it holds, and end it.
 *
 * Starting one carries no session of its own — that is the point of the login
 * code, which is the credential — so the route is rate limited instead, on top
 * of the per-workstation entry limit AUTH-029 already applies. Reading and
 * ending carry the session key.
 *
 * The response to a successful entry contains a session key and no bearer token,
 * and the request path issues neither an API token nor a device trust (AUTH-030,
 * data/API 12.4). A Kiosk that ends up with a personal device token has been
 * through some other endpoint.
 */
class SharedWorkstationSessionController extends Controller
{
    public function __construct(private readonly SharedWorkstationSessionService $sessions) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shared_workstation_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $workstation = SharedWorkstation::query()->find($validated['shared_workstation_id']);

        if (! $workstation instanceof SharedWorkstation) {
            return $this->refusal(SharedWorkstationLoginException::workstationUnknown());
        }

        try {
            $established = $this->sessions->start($workstation, $validated['code']);
        } catch (SharedWorkstationLoginException $exception) {
            return $this->refusal($exception);
        }

        return response()->json([
            // The one moment the key exists outside the Kiosk that will hold it.
            // It is not stored, logged, or retrievable again — which is what
            // makes "the session locks if the app restarts" a property of the
            // system rather than a promise from the renderer.
            'session_key' => $established->sessionKey,
        ] + $this->sessionPayload($established->record), 201);
    }

    /**
     * The session the caller holds.
     *
     * Also the "continue" action behind the timeout warning the kiosk guide asks
     * for: resolving the session is activity, so a person who says they are
     * still there slides the window by saying it.
     */
    public function show(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);

        if (! $session instanceof SharedWorkstationSession) {
            return $this->refusal(SharedWorkstationLoginException::invalidCode());
        }

        return response()->json($this->sessionPayload($session));
    }

    /**
     * Confirm the active user before a privileged action (M18.32; UI-017; UI
     * contract 12.8 `kiosk.reauth`, 18.2).
     *
     * Behind the workstation guard, because what is being re-confirmed is the
     * session the caller already holds. The typed code is checked against that
     * session's user, so a valid code belonging to somebody else confirms
     * nothing and hands nothing over.
     */
    public function reauthenticate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $session = $this->currentSession($request);

        if (! $session instanceof SharedWorkstationSession) {
            return $this->refusal(SharedWorkstationLoginException::noActiveSession());
        }

        try {
            $session = $this->sessions->reauthenticate($session, $validated['code']);
        } catch (SharedWorkstationLoginException $exception) {
            return $this->refusal($exception);
        }

        return response()->json($this->sessionPayload($session));
    }

    /**
     * The user ending their own session, which is what technical spec 13.3
     * requires before another user may sign in at the same workstation.
     */
    public function destroy(Request $request): JsonResponse
    {
        $session = $this->currentSession($request);

        if ($session instanceof SharedWorkstationSession) {
            $this->sessions->end($session, SharedWorkstationSession::ENDED_SIGNED_OUT);
        }

        // Answered the same way whether or not there was a session to end: a
        // Kiosk asking to be signed out gets to be signed out, and a session
        // that had already timed out is not an error to report to somebody
        // walking away from the machine.
        return response()->json(['ended' => true]);
    }

    /**
     * What a Kiosk needs to frame itself: who is signed in, where, in which
     * event, and how long is left.
     *
     * Roles and capabilities are not here. Those come from `GET /api/me`, which
     * the session key authenticates, so there is one answer to "what may this
     * user do" rather than two that can disagree.
     *
     * @return array<string, mixed>
     */
    private function sessionPayload(SharedWorkstationSession $session): array
    {
        $user = $session->user()->first();
        $workstation = $session->sharedWorkstation()->first();

        return [
            'session' => [
                'id' => $session->getKey(),
                'started_at' => $session->started_at?->toIso8601String(),
                'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                // The inactivity deadline, so the Kiosk counts down against the
                // node's clock rather than against its own idea of when it last
                // did something.
                'expires_at' => $session->expiresAt()->toIso8601String(),
                'inactivity_timeout_seconds' => SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES * 60,
                // Null until somebody re-confirms who they are (M18.32). It is
                // reported rather than interpreted: how recent a confirmation
                // must be belongs to the action that asks for one.
                'reauthenticated_at' => $session->reauthenticated_at?->toIso8601String(),
            ],
            // "The active user is shown prominently at all times" (technical
            // spec 13.3) needs the name to be in the session response rather
            // than something the Kiosk fetches separately and might not have.
            'user' => $user instanceof User ? [
                'id' => $user->getKey(),
                'name' => $user->name,
            ] : null,
            'shared_workstation' => $workstation instanceof SharedWorkstation ? [
                'id' => $workstation->getKey(),
                'name' => $workstation->name,
                'organization_id' => $workstation->organization_id,
                'department_id' => $workstation->department_id,
            ] : null,
            'event_id' => $session->event_id,
        ];
    }

    private function currentSession(Request $request): ?SharedWorkstationSession
    {
        $key = trim((string) $request->header(SharedWorkstationSessionKey::HEADER));

        return $key === '' ? null : $this->sessions->resolve($key);
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
