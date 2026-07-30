<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The credential a Kiosk presents to prove it holds a shared-workstation
 * session (AUTH-030; technical spec 13.3).
 *
 * Nothing about this is typed by a person, so it shares nothing with
 * {@see TypableCode}: no restricted alphabet, no eight-character length, no
 * tolerance for how somebody transcribes it. It is machine-generated, machine-read,
 * and long enough that guessing it is not a threat model.
 *
 * Only a keyed hash is stored. HMAC rather than a bare digest is not strictly
 * necessary at this length — unlike a login code, a 40-character random string
 * cannot be precomputed — but keying it namespaces the digest away from every
 * other credential hash in the database, so a hash lifted from one table cannot
 * be replayed against another.
 */
final class SharedWorkstationSessionKey
{
    public const LENGTH = 40;

    /** Domain separation for the stored hash. */
    private const HASH_NAMESPACE = 'meridian.shared-workstation-session.v1';

    /**
     * The header a Kiosk presents its session key in.
     *
     * Deliberately not `Authorization: Bearer`. AUTH-030 says a code entry
     * issues no personal device token, and a workstation session arriving in the
     * same header as one would be an invitation for something to treat it as a
     * token — logging it as such, refreshing it as such, or binding a device to
     * it. A different header makes the difference visible at the edge.
     */
    public const HEADER = 'X-Meridian-Workstation-Session';

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    public static function hash(string $key): string
    {
        return hash_hmac(
            'sha256',
            self::HASH_NAMESPACE.'|'.$key,
            (string) Config::get('app.key'),
        );
    }
}
