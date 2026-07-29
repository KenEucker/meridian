<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Config;

/**
 * Generates, formats, normalizes, and hashes API login codes (AUTH-019;
 * technical spec 11.4).
 *
 * A person reads this code off a screen and types it into an application, so
 * the alphabet excludes the characters that are read wrong when they are
 * transcribed: `I`, `L`, `O`, `U`, `0`, and `1`. That leaves 30 symbols over
 * eight characters, which is roughly 2^39 of entropy — enough that guessing is
 * bounded by the attempt limit and route throttling rather than by luck.
 *
 * Only a keyed hash is persisted. HMAC rather than a bare digest, because the
 * code space is small enough that a plain SHA-256 of every possible code could
 * be precomputed; keying it to the application key means a stolen database
 * still cannot be turned into a rainbow table.
 */
class ApiLoginCodeGenerator
{
    /** Unambiguous when transcribed by hand. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 8;

    public static function generate(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $code = '';

        for ($index = 0; $index < self::LENGTH; $index++) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }

    /**
     * The code as a person is shown it. The dash is presentation only and is
     * stripped again by {@see normalize()}, so a user may type it either way.
     */
    public static function format(string $code): string
    {
        $normalized = self::normalize($code);

        if (strlen($normalized) !== self::LENGTH) {
            return $normalized;
        }

        return substr($normalized, 0, 4).'-'.substr($normalized, 4);
    }

    /**
     * People type codes with the spacing, dashes, and case they happen to see,
     * none of which is meaningful.
     */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) Config::get('app.key'));
    }
}
