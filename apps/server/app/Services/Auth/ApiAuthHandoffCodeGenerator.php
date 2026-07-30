<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Generates and hashes the two secrets a provider handoff carries (AUTH-020;
 * technical spec 11.4).
 *
 * The handoff `state` proves that a provider callback belongs to a handoff this
 * node started, and the exchange code is what the system browser carries back to
 * the client to be spent for a bearer token. Neither is ever typed by a person —
 * both travel in URLs — so unlike the login codes in
 * {@see ApiLoginCodeGenerator} these are full-entropy random values rather than
 * short transcribable ones.
 *
 * Only keyed hashes are persisted. The values are long enough that a rainbow
 * table is not the threat a bare digest would invite; HMAC is used anyway so
 * that a database read cannot be turned into a usable credential by any means,
 * which is the same rule raw token values follow (AUTH-025).
 */
final class ApiAuthHandoffCodeGenerator
{
    /**
     * 43 alphanumeric characters — around 256 bits. Long enough that guessing
     * is not a threat model, and safe to place in a redirect for a custom
     * scheme without escaping.
     */
    public static function generate(): string
    {
        return Str::random(43);
    }

    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) Config::get('app.key'));
    }
}
