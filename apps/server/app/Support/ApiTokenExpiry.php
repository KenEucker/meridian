<?php

namespace App\Support;

/**
 * The node-configured lifetime of an issued API bearer token (AUTH-024;
 * technical spec 11.4; data/API specification 5.4).
 *
 * This lives outside the config files because two of them need the same number:
 * `meridian.api_tokens.expiration_minutes` is what issuance reads when it
 * stamps a token's own `expires_at`, and `sanctum.expiration` is what the
 * request-time guard reads. Resolving both through one method keeps a single
 * documented default rather than two literals that can drift apart.
 *
 * Token expiry is deliberately independent of the 5-minute shared-workstation
 * inactivity timeout in technical spec 13.3. The two govern different things:
 * this bounds how long a personal device stays authenticated, that bounds how
 * long an unattended shared workstation stays open.
 */
final class ApiTokenExpiry
{
    /**
     * Six weeks, in minutes.
     *
     * The documented default matches the trusted-session window in technical
     * spec 11.2 and the shared-workstation login code validity in 13.2. Users
     * are expected to authenticate before an event while internet exists, and
     * an event may then run for days without connectivity; a shorter default
     * would expire a field device partway through an event it could not
     * re-authenticate during.
     */
    public const DEFAULT_MINUTES = 60480;

    /**
     * The configured token lifetime in minutes.
     *
     * A missing, non-numeric, zero, or negative setting falls back to the
     * documented default rather than issuing tokens that never expire, because
     * AUTH-024 requires that bearer tokens expire.
     */
    public static function minutes(): int
    {
        $configured = env('MERIDIAN_API_TOKEN_EXPIRATION_MINUTES', self::DEFAULT_MINUTES);

        if (! is_numeric($configured)) {
            return self::DEFAULT_MINUTES;
        }

        $minutes = (int) $configured;

        return $minutes > 0 ? $minutes : self::DEFAULT_MINUTES;
    }
}
