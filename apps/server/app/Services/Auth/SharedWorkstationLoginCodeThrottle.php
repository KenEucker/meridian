<?php

namespace App\Services\Auth;

use App\Models\SharedWorkstation;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limits AUTH-029 puts on shared-workstation login codes: generation
 * limited per user and per node, code entry limited per workstation (data/API
 * 12.4).
 *
 * Three limits, because they bound three different things.
 *
 * Per user is keyed to the user the code is *for*, not to whoever generated it.
 * That is what bounds how many live codes can exist for one person, and it is
 * the limit that matters when a stolen session is the thing generating them.
 * Keying it to the operator instead would have made God mode's documented
 * event-preparation path — one technician generating codes for a roster of staff
 * (technical spec 13.2) — hit a limit meant for a different problem.
 *
 * Per node bounds the whole install, which is the limit that still holds when an
 * attacker has many user identities to spread generation across. It is set well
 * above the per-user limit so ordinary event preparation stays inside it.
 *
 * Per workstation bounds code *entry*, because guessing happens at the kiosk
 * keyboard rather than at generation. A successful entry clears the counter, so
 * a workstation where people are signing in normally is never throttled by its
 * own use.
 *
 * The counters live in the cache, which is per node — the right scope for all
 * three, since a code is entered on the node that issued it and an on-site node
 * often cannot reach anything else.
 */
class SharedWorkstationLoginCodeThrottle
{
    private const GENERATION_WINDOW_SECONDS = 3600;

    private const ENTRY_WINDOW_SECONDS = 60;

    public const DEFAULT_GENERATION_PER_USER = 5;

    public const DEFAULT_GENERATION_PER_NODE = 200;

    public const DEFAULT_ENTRY_ATTEMPTS_PER_WORKSTATION = 5;

    public const DEFAULT_FAILED_ENTRY_AUDIT_THRESHOLD = 3;

    /**
     * Refuse generation that would exceed either limit, and count it when it
     * would not.
     *
     * Both limits are checked before either is counted, so a refusal by one does
     * not spend a share of the other.
     *
     * @throws SharedWorkstationLoginException
     */
    public function guardGeneration(User $subject): void
    {
        $userKey = $this->userKey($subject);
        $nodeKey = $this->nodeKey();

        foreach ([[$userKey, $this->generationPerUser()], [$nodeKey, $this->generationPerNode()]] as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw SharedWorkstationLoginException::rateLimited(RateLimiter::availableIn($key));
            }
        }

        RateLimiter::hit($userKey, self::GENERATION_WINDOW_SECONDS);
        RateLimiter::hit($nodeKey, self::GENERATION_WINDOW_SECONDS);
    }

    /**
     * Refuse a code entry attempt at a workstation that has made too many, and
     * count this one.
     *
     * Every attempt is counted rather than only the failures, because a limit
     * that only counted failures could be reset by interleaving them with
     * anything that was not a failure.
     *
     * @throws SharedWorkstationLoginException
     */
    public function guardEntry(SharedWorkstation $workstation): void
    {
        $key = $this->workstationKey($workstation);

        if (RateLimiter::tooManyAttempts($key, $this->entryAttemptsPerWorkstation())) {
            throw SharedWorkstationLoginException::rateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::ENTRY_WINDOW_SECONDS);
    }

    /**
     * Count a wrong code at a workstation and answer how many wrong codes it has
     * seen in the current window.
     *
     * Technical spec 13.2 audits failed attempts "after a threshold", so the
     * count is what decides whether an entry is worth recording; a single
     * mistyped character at a busy kiosk is not.
     */
    public function recordEntryFailure(SharedWorkstation $workstation): int
    {
        return RateLimiter::hit($this->workstationFailureKey($workstation), self::ENTRY_WINDOW_SECONDS);
    }

    /**
     * A person signed in here, so this workstation is in use rather than under
     * attack.
     */
    public function clearEntryAttempts(SharedWorkstation $workstation): void
    {
        RateLimiter::clear($this->workstationKey($workstation));
        RateLimiter::clear($this->workstationFailureKey($workstation));
    }

    public function generationPerUser(): int
    {
        return $this->positiveConfig(
            'meridian.shared_workstation_login_codes.generation_per_user_per_hour',
            self::DEFAULT_GENERATION_PER_USER,
        );
    }

    public function generationPerNode(): int
    {
        return $this->positiveConfig(
            'meridian.shared_workstation_login_codes.generation_per_node_per_hour',
            self::DEFAULT_GENERATION_PER_NODE,
        );
    }

    public function entryAttemptsPerWorkstation(): int
    {
        return $this->positiveConfig(
            'meridian.shared_workstation_login_codes.entry_attempts_per_workstation_per_minute',
            self::DEFAULT_ENTRY_ATTEMPTS_PER_WORKSTATION,
        );
    }

    public function failedEntryAuditThreshold(): int
    {
        return $this->positiveConfig(
            'meridian.shared_workstation_login_codes.failed_entry_audit_threshold',
            self::DEFAULT_FAILED_ENTRY_AUDIT_THRESHOLD,
        );
    }

    private function userKey(User $subject): string
    {
        return 'shared-workstation-login-code:generate:user:'.$subject->getKey();
    }

    private function nodeKey(): string
    {
        return 'shared-workstation-login-code:generate:node';
    }

    private function workstationKey(SharedWorkstation $workstation): string
    {
        return 'shared-workstation-login-code:entry:workstation:'.$workstation->getKey();
    }

    private function workstationFailureKey(SharedWorkstation $workstation): string
    {
        return 'shared-workstation-login-code:entry-failed:workstation:'.$workstation->getKey();
    }

    /**
     * A missing, non-numeric, zero, or negative setting falls back to the
     * documented default rather than removing a limit AUTH-029 requires.
     */
    private function positiveConfig(string $key, int $default): int
    {
        $configured = config($key, $default);

        if (! is_numeric($configured)) {
            return $default;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : $default;
    }
}
