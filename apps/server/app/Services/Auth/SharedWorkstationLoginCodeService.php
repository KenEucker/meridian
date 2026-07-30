<?php

namespace App\Services\Auth;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates, consumes, and revokes shared-workstation login codes (AUTH-026
 * through AUTH-029; technical spec 13.2; data/API 12.4).
 *
 * This is the login path that survives an on-site node with no route to central.
 * A staff member standing at a kiosk with no internet, no mail delivery, and no
 * technician available generates a code on a phone that still holds a session
 * against that node, and types it into the workstation in front of them
 * (AUTH-027). Nothing here reaches the network: generation is a local write, and
 * entry is a local lookup.
 *
 * Two authorities generate the same kind of code (technical spec 13.2):
 *
 * - God mode generates one for any known user — the assisted-recovery and
 *   event-preparation path;
 * - a user generates one for themselves from a device where they already hold a
 *   valid session, and cannot generate one for anybody else (AUTH-028).
 *
 * Every code is scoped to one user, one event, and one trusted shared
 * workstation, and is valid for six weeks
 * ({@see SharedWorkstationLoginCode::VALID_DURATION_WEEKS}). The event is taken
 * from the workstation's pinned Kiosk context (technical spec 13.1) rather than
 * from the request, so a code cannot be scoped to an event the workstation it is
 * for does not serve.
 *
 * Only a keyed hash of a code is stored, and the plaintext is returned to the
 * caller that generated it and nowhere else — not to a log, an audit entry, or
 * an export (technical spec 13.2, data/API 12.4).
 *
 * What a successful entry then *establishes* is not here. Redemption answers
 * which user a code speaks for and retires it; the shared-workstation session
 * itself — its 5-minute inactivity timeout, explicit end before user switching,
 * lock on Electron restart, and the rule that no personal device token is issued
 * — is technical spec 13.3 and arrives with M16.9. Entry lives here because
 * AUTH-029's per-workstation attempt limiting and 13.2's minimal use audit and
 * after-threshold failure audit are properties of the code, not of the session.
 */
class SharedWorkstationLoginCodeService
{
    public const AUDIT_GENERATED = 'shared_workstation_login_code.generated';

    public const AUDIT_USED = 'shared_workstation_login_code.used';

    public const AUDIT_REVOKED = 'shared_workstation_login_code.revoked';

    public const AUDIT_ENTRY_FAILED = 'shared_workstation_login_code.entry_failed';

    /** A user generated a code for themselves (AUTH-026, AUTH-028). */
    public const AUTHORITY_SELF_SERVICE = 'self_service';

    /** God mode generated a code for a known user (AUTH-026, AUTH-028). */
    public const AUTHORITY_GOD_MODE = 'god_mode';

    /** A newer code for the same user and workstation replaced this one. */
    public const REASON_SUPERSEDED = 'superseded';

    /** A God Mode operator withdrew the code (technical spec 13.2). */
    public const REASON_GOD_MODE = 'god_mode';

    public function __construct(
        private readonly AuditService $audit,
        private readonly SharedWorkstationLoginCodeThrottle $throttle,
    ) {}

    /**
     * A user generates a code for themselves on a device where they already hold
     * a session (AUTH-026, AUTH-027, AUTH-028).
     *
     * @throws SharedWorkstationLoginException
     */
    public function generateForSelf(
        User $user,
        SharedWorkstation $workstation,
        ?Device $actorDevice = null,
    ): IssuedSharedWorkstationLoginCode {
        return $this->generate(
            subject: $user,
            generatedBy: $user,
            workstation: $workstation,
            authority: self::AUTHORITY_SELF_SERVICE,
            actorDevice: $actorDevice,
            sourceContext: AuditEvent::SOURCE_API,
        );
    }

    /**
     * God mode generates a code for a known user (AUTH-026, AUTH-028).
     *
     * The caller is responsible for having established that the operator holds
     * God Mode authority; what this enforces is that the operator is recorded as
     * having done it.
     *
     * @throws SharedWorkstationLoginException
     */
    public function generateForUser(
        User $subject,
        User $operator,
        SharedWorkstation $workstation,
    ): IssuedSharedWorkstationLoginCode {
        return $this->generate(
            subject: $subject,
            generatedBy: $operator,
            workstation: $workstation,
            authority: self::AUTHORITY_GOD_MODE,
            actorDevice: null,
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );
    }

    /**
     * Exchange a typed code for the code record that authorizes a session at this
     * workstation.
     *
     * The code is consumed inside a locked read, so two people racing the same
     * code at the same workstation cannot both be let in. Nothing about the
     * session is decided here, and no API token is issued (data/API 12.4).
     *
     * @throws SharedWorkstationLoginException
     */
    public function redeem(SharedWorkstation $workstation, string $code): SharedWorkstationLoginCode
    {
        $this->requireTrustedWorkstation($workstation);

        // Checked before the lookup, so a workstation being hammered stops
        // producing lookups rather than being throttled after the fact.
        $this->throttle->guardEntry($workstation);

        $codeHash = SharedWorkstationLoginCodeGenerator::hash($code);

        $record = DB::transaction(function () use ($workstation, $codeHash): ?SharedWorkstationLoginCode {
            $found = SharedWorkstationLoginCode::query()
                ->where('shared_workstation_id', $workstation->getKey())
                ->where('code_hash', $codeHash)
                ->active()
                ->lockForUpdate()
                ->first();

            $found?->forceFill(['used_at' => now()])->save();

            return $found;
        });

        if (! $record instanceof SharedWorkstationLoginCode) {
            $this->recordFailedEntry($workstation);

            throw SharedWorkstationLoginException::invalidCode();
        }

        $user = $record->user()->first();

        // Checked after the code is spent, and deliberately so — the same
        // decision the mailed API login code makes. The code did its job by
        // proving possession; a refusal about the account is not a reason to hand
        // the credential back for another try.
        if (! $user instanceof User || $user->isDisabled()) {
            throw SharedWorkstationLoginException::accountDisabled();
        }

        $this->throttle->clearEntryAttempts($workstation);

        // "Minimally audited when used" (technical spec 13.2): who signed in,
        // where, and under which code. No raw code, and no session detail,
        // because the session is not established here.
        $this->audit->recordForEntity(
            entity: $record,
            action: self::AUDIT_USED,
            actorUser: $user,
            actorDevice: $workstation->device()->first(),
            organizationId: $workstation->organization_id,
            eventId: $record->event_id,
            departmentId: $workstation->department_id,
            after: [
                'login_code_id' => $record->getKey(),
                'shared_workstation_id' => $workstation->getKey(),
                'user_id' => $record->user_id,
                'event_id' => $record->event_id,
            ],
            sourceContext: AuditEvent::SOURCE_API,
        );

        return $record;
    }

    /**
     * God mode withdraws a code before it is used (technical spec 13.2, "revocable
     * by God mode").
     *
     * Returns false when the code was already used, revoked, or expired, so a
     * repeated action does not accumulate audit entries about a credential that
     * was already gone.
     */
    public function revoke(SharedWorkstationLoginCode $code, User $operator): bool
    {
        if ($code->isUsed() || $code->isRevoked() || $code->isExpired()) {
            return false;
        }

        return $this->retire($code, $operator, self::REASON_GOD_MODE, AuditEvent::SOURCE_ORCHID);
    }

    /**
     * The one path both authorities generate through.
     *
     * Public because the rules it enforces belong to every caller, not only to
     * the two wrappers above: a self-service code is refused for anyone but the
     * generating user (AUTH-028), the workstation must be trusted and pinned to an
     * event this node holds, and the rate limits apply either way.
     *
     * @param  string  $authority  which authority in technical spec 13.2 this is
     * @param  Device|null  $actorDevice  the device the generating session is on,
     *                                    recorded as the audit actor device
     *
     * @throws SharedWorkstationLoginException
     */
    public function generate(
        User $subject,
        User $generatedBy,
        SharedWorkstation $workstation,
        string $authority,
        ?Device $actorDevice = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): IssuedSharedWorkstationLoginCode {
        // AUTH-028: a self-service code is scoped to the generating user. This is
        // also enforced by the API endpoint carrying no user field at all, but the
        // rule belongs to the domain rather than to one caller of it.
        if ($authority === self::AUTHORITY_SELF_SERVICE && ! $generatedBy->is($subject)) {
            throw SharedWorkstationLoginException::selfServiceScope();
        }

        $this->requireTrustedWorkstation($workstation);
        $this->requirePinnedEvent($workstation);

        if ($subject->isDisabled()) {
            throw SharedWorkstationLoginException::accountDisabled();
        }

        $this->throttle->guardGeneration($subject);

        $plaintextCode = SharedWorkstationLoginCodeGenerator::generate();
        $generatedAt = now();

        $record = DB::transaction(function () use (
            $subject,
            $generatedBy,
            $workstation,
            $authority,
            $actorDevice,
            $sourceContext,
            $plaintextCode,
            $generatedAt,
        ): SharedWorkstationLoginCode {
            $superseded = $this->retireOutstandingCodes($subject, $workstation, $generatedBy, $sourceContext);

            /** @var SharedWorkstationLoginCode $record */
            $record = SharedWorkstationLoginCode::query()->create([
                'user_id' => $subject->getKey(),
                'event_id' => $workstation->event_id,
                'shared_workstation_id' => $workstation->getKey(),
                'code_hash' => SharedWorkstationLoginCodeGenerator::hash($plaintextCode),
                'expires_at' => SharedWorkstationLoginCode::expiresAtFrom($generatedAt),
                'generated_by_user_id' => $generatedBy->getKey(),
            ]);

            // Audited in the same transaction as the code, so a code cannot exist
            // unrecorded (data/API 12.4, "generation and use are audited").
            $this->audit->recordForEntity(
                entity: $record,
                action: self::AUDIT_GENERATED,
                actorUser: $generatedBy,
                actorDevice: $actorDevice,
                organizationId: $workstation->organization_id,
                eventId: $workstation->event_id,
                departmentId: $workstation->department_id,
                after: [
                    // Identifiers and scope only. The code itself exists in the
                    // response to the request that generated it and nowhere else.
                    'login_code_id' => $record->getKey(),
                    'user_id' => $subject->getKey(),
                    'event_id' => $workstation->event_id,
                    'shared_workstation_id' => $workstation->getKey(),
                    'generated_by_user_id' => $generatedBy->getKey(),
                    'expires_at' => $record->expires_at?->toIso8601String(),
                    'superseded_login_code_ids' => $superseded->all(),
                ],
                reason: $authority,
                sourceContext: $sourceContext,
            );

            return $record;
        });

        return new IssuedSharedWorkstationLoginCode($record, $plaintextCode);
    }

    /**
     * A code generated for a user at a workstation replaces any code they still
     * hold for that same workstation.
     *
     * Someone who has forgotten a code generates another, and a six-week credential
     * left live behind it would be a second way in that nobody is tracking. This
     * mirrors what the mailed API login code does for the same reason.
     *
     * @return Collection<int, string>  the identifiers of the codes retired
     */
    private function retireOutstandingCodes(
        User $subject,
        SharedWorkstation $workstation,
        User $generatedBy,
        string $sourceContext,
    ): Collection {
        $outstanding = SharedWorkstationLoginCode::query()
            ->where('user_id', $subject->getKey())
            ->where('shared_workstation_id', $workstation->getKey())
            ->active()
            ->get();

        foreach ($outstanding as $code) {
            $this->retire($code, $generatedBy, self::REASON_SUPERSEDED, $sourceContext);
        }

        return $outstanding->map(fn (SharedWorkstationLoginCode $code): string => (string) $code->getKey());
    }

    private function retire(
        SharedWorkstationLoginCode $code,
        User $operator,
        string $reason,
        string $sourceContext,
    ): bool {
        $code->forceFill(['revoked_at' => now()])->save();

        $workstation = $code->sharedWorkstation()->first();

        $this->audit->recordForEntity(
            entity: $code,
            action: self::AUDIT_REVOKED,
            actorUser: $operator,
            organizationId: $workstation?->organization_id,
            eventId: $code->event_id,
            departmentId: $workstation?->department_id,
            after: [
                'login_code_id' => $code->getKey(),
                'user_id' => $code->user_id,
                'shared_workstation_id' => $code->shared_workstation_id,
            ],
            reason: $reason,
            sourceContext: $sourceContext,
        );

        return true;
    }

    /**
     * Technical spec 13.2: codes are usable only on trusted shared workstations.
     * A workstation that is untrusted, revoked, or backed by a revoked device is
     * refused for generation and for entry alike, because a code that could not
     * be entered is not worth issuing.
     *
     * @throws SharedWorkstationLoginException
     */
    private function requireTrustedWorkstation(SharedWorkstation $workstation): void
    {
        if (! $workstation->isTrusted()) {
            throw SharedWorkstationLoginException::workstationUntrusted();
        }
    }

    /**
     * Technical spec 13.1: a Kiosk workstation is pinned to one organization and
     * one event before normal operation, and a code is scoped to that event.
     *
     * The pinned event has to resolve to an event record on this node, not just
     * to an identifier. `shared_workstations.event_id` carries no database
     * foreign key — the table predates the `events` migration — so a workstation
     * pinned to an event this node does not hold is representable, and a code
     * scoped to it could not be audited against the event it claims.
     *
     * @throws SharedWorkstationLoginException
     */
    private function requirePinnedEvent(SharedWorkstation $workstation): void
    {
        if ($workstation->event_id === null || ! $workstation->event()->exists()) {
            throw SharedWorkstationLoginException::workstationContextUnpinned();
        }
    }

    /**
     * Count a wrong code and audit it once the workstation has made enough of
     * them to be worth recording (technical spec 13.2, "failed login-code
     * attempts are audited after a threshold").
     *
     * The entry is against the workstation, not against a code: a wrong code
     * matched nothing, so there is no code to attribute it to.
     */
    private function recordFailedEntry(SharedWorkstation $workstation): void
    {
        $failures = $this->throttle->recordEntryFailure($workstation);

        if ($failures !== $this->throttle->failedEntryAuditThreshold()) {
            return;
        }

        $this->audit->recordForEntity(
            entity: $workstation,
            action: self::AUDIT_ENTRY_FAILED,
            actorDevice: $workstation->device()->first(),
            organizationId: $workstation->organization_id,
            eventId: $workstation->event_id,
            departmentId: $workstation->department_id,
            after: [
                'shared_workstation_id' => $workstation->getKey(),
                'failed_attempts' => $failures,
            ],
            sourceContext: AuditEvent::SOURCE_API,
        );
    }
}
