<?php

namespace App\Support;

/**
 * The shape of a code a person reads off one screen and types into another.
 *
 * Meridian hands out two such codes for two different reasons — the mailed API
 * login code (AUTH-019; technical spec 11.4) and the shared-workstation login
 * code (AUTH-026; technical spec 13.2) — and both are typed by hand, so both
 * want the same alphabet, the same length, and the same tolerance for how a
 * person happens to transcribe them. Only the keying of the stored hash differs,
 * which is why hashing stays with each credential rather than living here.
 *
 * The alphabet excludes the characters that are read wrong when transcribed:
 * `I`, `L`, `O`, `U`, `0`, and `1`. That leaves 30 symbols over eight
 * characters, roughly 2^39 of entropy — enough that guessing is bounded by
 * attempt limits and rate limiting rather than by luck.
 */
final class TypableCode
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
}
