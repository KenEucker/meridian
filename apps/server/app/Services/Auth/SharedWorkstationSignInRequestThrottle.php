<?php

namespace App\Services\Auth;

use App\Models\SharedWorkstation;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limits AUTH-037 puts on workstation sign-in requests: opening
 * limited per workstation, granting limited per user (technical spec 13.4;
 * data/API 12.4A).
 *
 * Two limits, bounding the two ends of the scan.
 *
 * Per workstation bounds opening, because the open route is unauthenticated —
 * a locked machine has no credential to ask with — and an unauthenticated
 * route that wrote a row per call would be a table anybody could fill. The
 * default leaves room for a Kiosk refreshing an expired request every couple
 * of minutes plus a person retrying, and not for much else.
 *
 * Per user bounds granting, because a grant is the authenticated half and the
 * thing a stolen session would be doing in a loop. Every attempt is counted,
 * not only the successes, so a refusal cannot be used to probe for free.
 *
 * The counters live in the cache, which is per node — the right scope for
 * both, since a request is granted on the node that opened it.
 */
class SharedWorkstationSignInRequestThrottle
{
    private const WINDOW_SECONDS = 60;

    public const DEFAULT_OPEN_PER_WORKSTATION = 10;

    public const DEFAULT_GRANT_PER_USER = 10;

    /**
     * Refuse an open that would exceed the workstation's limit, and count it.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    public function guardOpen(SharedWorkstation $workstation): void
    {
        $key = 'workstation-sign-in-request:open:workstation:'.$workstation->getKey();

        if (RateLimiter::tooManyAttempts($key, $this->openPerWorkstation())) {
            throw SharedWorkstationSignInRequestException::rateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    /**
     * Refuse a grant attempt that would exceed the user's limit, and count it.
     *
     * @throws SharedWorkstationSignInRequestException
     */
    public function guardGrant(User $user): void
    {
        $key = 'workstation-sign-in-request:grant:user:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, $this->grantPerUser())) {
            throw SharedWorkstationSignInRequestException::rateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    public function openPerWorkstation(): int
    {
        return $this->positiveConfig(
            'meridian.workstation_sign_in_requests.open_per_workstation_per_minute',
            self::DEFAULT_OPEN_PER_WORKSTATION,
        );
    }

    public function grantPerUser(): int
    {
        return $this->positiveConfig(
            'meridian.workstation_sign_in_requests.grant_per_user_per_minute',
            self::DEFAULT_GRANT_PER_USER,
        );
    }

    /**
     * A missing, non-numeric, zero, or negative setting falls back to the
     * documented default rather than removing a limit AUTH-037 requires.
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
