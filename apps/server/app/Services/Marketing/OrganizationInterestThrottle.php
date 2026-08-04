<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\RateLimiter;

/**
 * The two limits PUBLIC-005 puts on organization interest submissions: per
 * contact address, and per submitting client.
 *
 * They bound different abuses. Per address bounds one address filling the
 * console with the same inquiry twenty times, whether that is a script or a
 * person hitting refresh. Per client bounds one machine submitting under a
 * different address each time, which is the shape automated submission takes
 * and the one a per-address limit never sees.
 *
 * Both limits are counted around every submission, including the ones the
 * PUBLIC-005 traps discard. A submission that is thrown away still cost the
 * node a request, and a throttle that only counted the submissions it kept
 * would let a client that trips a trap on every attempt run without a ceiling.
 *
 * Addresses are hashed into the cache key rather than written into it, matching
 * {@see \App\Services\Application\ApplicantPortalThrottle}: the counters live in
 * the node's cache, which is read by more tools than this one, and a key naming
 * every address that has written in would be a contact list sitting outside the
 * database.
 */
class OrganizationInterestThrottle
{
    private const WINDOW_SECONDS = 3600;

    public const DEFAULT_PER_EMAIL = 3;

    public const DEFAULT_PER_CLIENT = 10;

    /**
     * Refuse a submission that would exceed either limit, and count it when it
     * would not.
     *
     * Both limits are checked before either is counted, so a refusal by one
     * does not spend a share of the other.
     *
     * @param  string|null  $clientKey  the submitting client, normally its IP address
     *
     * @throws OrganizationInterestRateLimitException
     */
    public function guard(string $normalizedEmail, ?string $clientKey = null): void
    {
        $limits = [[$this->emailKey($normalizedEmail), $this->perEmail()]];

        if ($clientKey !== null && $clientKey !== '') {
            $limits[] = [$this->clientKey($clientKey), $this->perClient()];
        }

        foreach ($limits as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new OrganizationInterestRateLimitException(RateLimiter::availableIn($key));
            }
        }

        foreach ($limits as [$key]) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }
    }

    public function perEmail(): int
    {
        return $this->positiveConfig(
            'meridian.marketing.interest_submissions_per_email_per_hour',
            self::DEFAULT_PER_EMAIL,
        );
    }

    public function perClient(): int
    {
        return $this->positiveConfig(
            'meridian.marketing.interest_submissions_per_client_per_hour',
            self::DEFAULT_PER_CLIENT,
        );
    }

    private function emailKey(string $normalizedEmail): string
    {
        return 'organization-interest:email:'.hash('sha256', $normalizedEmail);
    }

    private function clientKey(string $clientKey): string
    {
        return 'organization-interest:client:'.hash('sha256', $clientKey);
    }

    /**
     * A missing, non-numeric, zero, or negative setting falls back to the
     * documented default rather than removing a limit PUBLIC-005 requires.
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
