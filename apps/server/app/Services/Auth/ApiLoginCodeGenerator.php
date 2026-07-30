<?php

namespace App\Services\Auth;

use App\Support\TypableCode;
use Illuminate\Support\Facades\Config;

/**
 * Generates, formats, normalizes, and hashes API login codes (AUTH-019;
 * technical spec 11.4).
 *
 * A person reads this code out of their mail and types it into an application,
 * so its alphabet and length come from {@see TypableCode}, which Meridian's
 * other hand-typed credential — the shared-workstation login code — draws on
 * too.
 *
 * Only a keyed hash is persisted. HMAC rather than a bare digest, because the
 * code space is small enough that a plain SHA-256 of every possible code could
 * be precomputed; keying it to the application key means a stolen database
 * still cannot be turned into a rainbow table.
 */
class ApiLoginCodeGenerator
{
    /** Unambiguous when transcribed by hand. */
    public const ALPHABET = TypableCode::ALPHABET;

    public const LENGTH = TypableCode::LENGTH;

    public static function generate(): string
    {
        return TypableCode::generate();
    }

    /**
     * The code as a person is shown it. The dash is presentation only and is
     * stripped again by {@see normalize()}, so a user may type it either way.
     */
    public static function format(string $code): string
    {
        return TypableCode::format($code);
    }

    /**
     * People type codes with the spacing, dashes, and case they happen to see,
     * none of which is meaningful.
     */
    public static function normalize(string $code): string
    {
        return TypableCode::normalize($code);
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) Config::get('app.key'));
    }
}
