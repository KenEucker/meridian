<?php

namespace App\Services\Auth;

use App\Support\TypableCode;
use Illuminate\Support\Facades\Config;

/**
 * Generates, formats, normalizes, and hashes shared-workstation login codes
 * (AUTH-026; technical spec 13.2; data/API 12.4).
 *
 * Technical spec 13.2 requires these codes to be human-typable, and the person
 * typing one is standing at a kiosk reading it off their own phone, so the
 * alphabet and length come from {@see TypableCode} — the same shape as the
 * mailed API login code, for the same transcription reasons.
 *
 * Only a keyed hash is persisted (data/API 12.4, "raw login codes are not
 * logged"). HMAC rather than a bare digest, because the code space is small
 * enough that a plain SHA-256 of every possible code could be precomputed.
 *
 * The key is namespaced away from the API login code hash. Both credentials are
 * eight characters from one alphabet, so an unnamespaced HMAC would produce the
 * same digest for the same characters in both tables — and a hash lifted from
 * one table would then match a code in the other. Namespacing keeps a code
 * meaningful only against the credential it was issued as.
 */
class SharedWorkstationLoginCodeGenerator
{
    /** Unambiguous when transcribed by hand. */
    public const ALPHABET = TypableCode::ALPHABET;

    public const LENGTH = TypableCode::LENGTH;

    /** Domain separation for the stored hash. */
    private const HASH_NAMESPACE = 'meridian.shared-workstation-login-code.v1';

    public static function generate(): string
    {
        return TypableCode::generate();
    }

    /**
     * The code as a person is shown it on the device that generated it. The dash
     * is presentation only and is stripped again by {@see normalize()}, so it may
     * be typed either way.
     */
    public static function format(string $code): string
    {
        return TypableCode::format($code);
    }

    public static function normalize(string $code): string
    {
        return TypableCode::normalize($code);
    }

    public static function hash(string $code): string
    {
        return hash_hmac(
            'sha256',
            self::HASH_NAMESPACE.'|'.self::normalize($code),
            (string) Config::get('app.key'),
        );
    }
}
