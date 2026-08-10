<?php

namespace App\Services\Auth;

use App\Models\AuditEvent;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\SharedWorkstationSignInRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Establishes, sustains, and ends shared-workstation sessions (AUTH-030;
 * technical spec 13.3; data/API 12.3).
 *
 * {@see SharedWorkstationLoginCodeService} answers which user a typed code
 * speaks for and retires the code. This answers what that entitles them to: a
 * session at one workstation, scoped to the event the code was scoped to, that
 * ends five minutes after the last thing they did.
 *
 * Three properties of technical spec 13.3 are enforced here rather than in the
 * client, because a Kiosk is a machine strangers stand in front of and a client
 * promise is not an enforcement:
 *
 * - the five-minute inactivity timeout, which {@see resolve()} applies on every
 *   request rather than trusting a countdown in a renderer;
 * - "permissions come entirely from the active user", which holds because a
 *   resolved session produces a `User` and nothing else — the workstation's
 *   pinned context frames the shell and grants no authority;
 * - "if the Electron app restarts, the shared workstation session locks
 *   immediately", which holds because the raw session key is returned once and
 *   never stored, so a restarted Kiosk has nothing left to present.
 *
 * "Users must explicitly end their session before switching users" is a rule
 * about the surface, not about this service, and is enforced in the Kiosk shell:
 * a workstation with a live session offers no code entry. It is deliberately not
 * enforced here as a refusal to start a second session, because a Kiosk that
 * crashed still holds an open session it can no longer present, and refusing
 * would have locked the workstation out for up to five minutes at exactly the
 * moment somebody needs it. {@see start()} supersedes instead, and records that
 * it did.
 */
class SharedWorkstationSessionService
{
    public const AUDIT_ENDED = 'shared_workstation_session.ended';

    public const AUDIT_REAUTHENTICATED = 'shared_workstation_session.reauthenticated';

    public function __construct(
        private readonly SharedWorkstationLoginCodeService $loginCodes,
        private readonly AuditService $audit,
    ) {}

    /**
     * Exchange a typed login code for a session at this workstation.
     *
     * No API token is issued and no device is trusted (AUTH-030, data/API 12.4).
     * The only credential this produces is the session key, which is bound to
     * the workstation rather than to anybody's device.
     *
     * @throws SharedWorkstationLoginException
     */
    public function start(SharedWorkstation $workstation, string $code): EstablishedSharedWorkstationSession
    {
        // Redemption enforces the trusted-workstation rule, the per-workstation
        // entry limit, and the disabled-account refusal, and spends the code
        // under a lock so two people racing one code cannot both be let in.
        $record = $this->loginCodes->redeem($workstation, $code);

        return $this->establish($workstation, (string) $record->user_id, (string) $record->event_id, [
            'login_code_id' => $record->getKey(),
        ]);
    }

    /**
     * Exchange a collected sign-in request for a session at this workstation
     * (M18.59; AUTH-035; technical spec 13.4).
     *
     * The same session a typed code produces, with the same rules: no API token,
     * no device trust, the key returned once and held in memory only. The
     * provenance differs — `sign_in_request_id` instead of `login_code_id` — so
     * the record says which path signed the person in.
     *
     * Granting, expiry, single-collection, and the opener-only rule are all
     * {@see SharedWorkstationSignInRequestService::collect()}'s; by the time
     * this runs, the request has been spent under a lock.
     */
    public function startFromSignInRequest(
        SharedWorkstation $workstation,
        SharedWorkstationSignInRequest $request,
    ): EstablishedSharedWorkstationSession {
        return $this->establish($workstation, (string) $request->granted_by_user_id, (string) $request->event_id, [
            'sign_in_request_id' => $request->getKey(),
        ]);
    }

    /**
     * The one path every session is established through, whatever credential
     * proved the person: close what the workstation still had open, create the
     * session, and hand the raw key back exactly once.
     *
     * @param  array<string, string>  $provenance  which credential established it
     */
    private function establish(
        SharedWorkstation $workstation,
        string $userId,
        string $eventId,
        array $provenance,
    ): EstablishedSharedWorkstationSession {
        $sessionKey = SharedWorkstationSessionKey::generate();
        $startedAt = now();

        $session = DB::transaction(function () use ($workstation, $userId, $eventId, $provenance, $sessionKey, $startedAt): SharedWorkstationSession {
            $this->closeOpenSessions($workstation, $startedAt);

            /** @var SharedWorkstationSession $session */
            $session = SharedWorkstationSession::query()->create([
                'shared_workstation_id' => $workstation->getKey(),
                'user_id' => $userId,
                // From the credential, which took it from the workstation's
                // pinned Kiosk context (technical spec 13.1) — never from the
                // request.
                'event_id' => $eventId,
                'session_key_hash' => SharedWorkstationSessionKey::hash($sessionKey),
                'started_at' => $startedAt,
                'last_activity_at' => $startedAt,
                ...$provenance,
            ]);

            return $session;
        });

        return new EstablishedSharedWorkstationSession($session, $sessionKey);
    }

    /**
     * Confirm that the person at the keyboard is still the signed-in user
     * (M18.32; UI-017; UI contract 12.8 `kiosk.reauth`, 18.2).
     *
     * "Privileged actions may require re-authentication", and Alpha 1 has no
     * separate Meridian PIN to require — 18.2 rules one out as an independent
     * central credential. What it does have is the login code, which is already
     * scoped to one user, one event, and this workstation, is single use, and is
     * generated in seconds from the phone in the user's pocket (AUTH-027). So a
     * confirmation is a fresh code for the same user, redeemed through the same
     * path an entry goes through: the trusted-workstation rule, the
     * per-workstation attempt limit, and the use audit all apply unchanged.
     *
     * A code for anybody else is refused and no session changes hands. Switching
     * users is {@see start()} after an explicit end, and 13.3 is deliberate that
     * there is no quiet handover.
     *
     * What this records is a timestamp on the session. Which actions demand a
     * recent one, and how recent, belongs to those actions rather than here.
     *
     * @throws SharedWorkstationLoginException
     */
    public function reauthenticate(SharedWorkstationSession $session, string $code): SharedWorkstationSession
    {
        $workstation = $session->sharedWorkstation()->first();

        if (! $workstation instanceof SharedWorkstation || ! $session->isActive()) {
            throw SharedWorkstationLoginException::noActiveSession();
        }

        $record = $this->loginCodes->redeem($workstation, $code);

        if ((string) $record->user_id !== (string) $session->user_id) {
            throw SharedWorkstationLoginException::reauthenticationMismatch();
        }

        $confirmedAt = now();

        $session->forceFill([
            'reauthenticated_at' => $confirmedAt,
            'last_activity_at' => $confirmedAt,
        ])->save();

        $this->audit->recordForEntity(
            entity: $session,
            action: self::AUDIT_REAUTHENTICATED,
            actorUser: $session->user()->first(),
            actorDevice: $workstation->device()->first(),
            organizationId: $workstation->organization_id,
            eventId: $session->event_id,
            departmentId: $workstation->department_id,
            after: [
                'shared_workstation_session_id' => $session->getKey(),
                'shared_workstation_id' => $workstation->getKey(),
                'user_id' => $session->user_id,
                'login_code_id' => $record->getKey(),
                'reauthenticated_at' => $confirmedAt->toIso8601String(),
            ],
            sourceContext: AuditEvent::SOURCE_API,
        );

        return $session;
    }

    /**
     * The session a presented key stands for, or null.
     *
     * A session found past its inactivity window is ended here rather than
     * merely refused, so the timeout leaves a record of itself. It is stamped at
     * the moment it actually expired rather than at the moment somebody noticed:
     * a workstation nobody touched for an hour ended five minutes in, and an
     * `ended_at` of now would say the user was signed in for the hour.
     */
    public function resolve(string $sessionKey): ?SharedWorkstationSession
    {
        $session = SharedWorkstationSession::query()
            ->where('session_key_hash', SharedWorkstationSessionKey::hash($sessionKey))
            ->open()
            ->first();

        if (! $session instanceof SharedWorkstationSession) {
            return null;
        }

        if ($session->hasTimedOut()) {
            $this->end($session, SharedWorkstationSession::ENDED_TIMED_OUT, $session->expiresAt());

            return null;
        }

        // Every authenticated request is activity. Nothing in the Kiosk polls on
        // a timer while idle, so a sliding window driven by requests is a
        // sliding window driven by the person at the keyboard.
        $session->forceFill(['last_activity_at' => now()])->save();

        return $session;
    }

    /**
     * The user a presented key signs in, or null.
     *
     * A session whose user has since been disabled resolves to nobody, because
     * the session was never authority of its own — it carries whatever authority
     * the active user has now (technical spec 13.3, "permissions come entirely
     * from the active user").
     */
    public function resolveUser(string $sessionKey): ?User
    {
        $session = $this->resolve($sessionKey);

        if (! $session instanceof SharedWorkstationSession) {
            return null;
        }

        $user = $session->user()->first();

        if (! $user instanceof User || $user->isDisabled()) {
            return null;
        }

        return $user;
    }

    /**
     * End a session and record why (technical spec 13.3).
     *
     * What this does not do is touch the local queue. Wiping session data is the
     * client's half of the same rule, and the kiosk guide is specific that
     * queued commands are not session data: a check-in that was recorded and
     * queued survives the session ending. Nothing here writes to, drains, or
     * discards anything a device has queued.
     */
    public function end(SharedWorkstationSession $session, string $reason, ?Carbon $endedAt = null): void
    {
        if ($session->hasEnded()) {
            return;
        }

        $session->forceFill([
            'ended_at' => $endedAt ?? now(),
            'ended_reason' => $reason,
        ])->save();

        $workstation = $session->sharedWorkstation()->first();

        // Why a session ended is the part that is not already recorded: the
        // start is the `shared_workstation_login_code.used` entry plus this row,
        // but "timed out" against "signed out" against "superseded" is what a
        // technician is actually asking about afterwards.
        $this->audit->recordForEntity(
            entity: $session,
            action: self::AUDIT_ENDED,
            actorUser: $session->user()->first(),
            actorDevice: $workstation?->device()->first(),
            organizationId: $workstation?->organization_id,
            eventId: $session->event_id,
            departmentId: $workstation?->department_id,
            after: [
                'shared_workstation_session_id' => $session->getKey(),
                'shared_workstation_id' => $session->shared_workstation_id,
                'user_id' => $session->user_id,
                'event_id' => $session->event_id,
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
            ],
            reason: $reason,
            sourceContext: AuditEvent::SOURCE_API,
        );
    }

    /**
     * The session currently live at a workstation, if any.
     */
    public function activeSessionFor(SharedWorkstation $workstation): ?SharedWorkstationSession
    {
        return SharedWorkstationSession::query()
            ->where('shared_workstation_id', $workstation->getKey())
            ->active()
            ->latest('started_at')
            ->first();
    }

    /**
     * Close whatever the workstation still has open before a new session starts.
     *
     * A session that had already timed out is recorded as having timed out, at
     * the moment it did; one that was still live was genuinely superseded. The
     * distinction matters because the second means somebody signed in over
     * another person's live session and the first means they did not.
     *
     */
    private function closeOpenSessions(SharedWorkstation $workstation, Carbon $at): void
    {
        $open = SharedWorkstationSession::query()
            ->where('shared_workstation_id', $workstation->getKey())
            ->open()
            ->lockForUpdate()
            ->get();

        foreach ($open as $session) {
            $session->hasTimedOut($at)
                ? $this->end($session, SharedWorkstationSession::ENDED_TIMED_OUT, $session->expiresAt())
                : $this->end($session, SharedWorkstationSession::ENDED_SUPERSEDED, $at);
        }
    }
}
