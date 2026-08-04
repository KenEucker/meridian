<?php

namespace App\Services\Application;

use Illuminate\Support\Facades\RateLimiter;

/**
 * The two limits APP-015 puts on applicant portal link requests: per email
 * address, and per requesting client.
 *
 * They bound two different things. Per address bounds how much mail one
 * person's inbox can be made to receive by somebody who knows their address,
 * which is the limit that matters when the request form is used as a way to
 * annoy an applicant rather than to reach a portal. Per client bounds walking a
 * list of addresses, which is the shape an enumeration attempt takes even
 * though the responses disclose nothing — a limit here is what stops the
 * *mailbox* from becoming the oracle the response refuses to be.
 *
 * The limits are checked and counted around every request, including requests
 * for addresses with no applications. Counting only the addresses Meridian
 * knows would make the throttle itself the disclosure the response avoids: a
 * client that is never refused has learned that none of the addresses it tried
 * exist.
 *
 * Addresses are hashed into the cache key rather than written into it. The
 * counters live in the node's cache, which is read by more tools than this one,
 * and a key naming every address that has asked for a link would be a list of
 * applicants sitting outside the database.
 */
class ApplicantPortalThrottle
{
    private const WINDOW_SECONDS = 3600;

    public const DEFAULT_PER_EMAIL = 5;

    public const DEFAULT_PER_CLIENT = 20;

    /**
     * Refuse a request that would exceed either limit, and count it when it
     * would not.
     *
     * Both limits are checked before either is counted, so a refusal by one
     * does not spend a share of the other.
     *
     * @param  string|null  $clientKey  the requesting client, normally its IP address
     *
     * @throws ApplicantPortalRateLimitException
     */
    public function guard(string $normalizedEmail, ?string $clientKey = null): void
    {
        $limits = [[$this->emailKey($normalizedEmail), $this->perEmail()]];

        if ($clientKey !== null && $clientKey !== '') {
            $limits[] = [$this->clientKey($clientKey), $this->perClient()];
        }

        foreach ($limits as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new ApplicantPortalRateLimitException(RateLimiter::availableIn($key));
            }
        }

        foreach ($limits as [$key]) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
        }
    }

    public function perEmail(): int
    {
        return $this->positiveConfig(
            'meridian.applicant_portal.link_requests_per_email_per_hour',
            self::DEFAULT_PER_EMAIL,
        );
    }

    public function perClient(): int
    {
        return $this->positiveConfig(
            'meridian.applicant_portal.link_requests_per_client_per_hour',
            self::DEFAULT_PER_CLIENT,
        );
    }

    private function emailKey(string $normalizedEmail): string
    {
        return 'applicant-portal:link-request:email:'.hash('sha256', $normalizedEmail);
    }

    private function clientKey(string $clientKey): string
    {
        return 'applicant-portal:link-request:client:'.hash('sha256', $clientKey);
    }

    /**
     * A missing, non-numeric, zero, or negative setting falls back to the
     * documented default rather than removing a limit APP-015 requires.
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
