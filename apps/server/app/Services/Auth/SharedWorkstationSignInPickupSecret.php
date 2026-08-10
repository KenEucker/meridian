<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The private half of a workstation sign-in request (AUTH-032, AUTH-037;
 * technical spec 13.4).
 *
 * Returned once, to the workstation that opened the request, and stored only
 * as a keyed hash. It is what later collects the session key, which is why the
 * unauthenticated open route is safe to expose: the request id in the QR is
 * the public half, and everything a bystander can photograph grants nothing.
 *
 * Machine-generated and machine-read, so it shares nothing with
 * {@see \App\Support\TypableCode} — no restricted alphabet, no eight-character
 * length. The HMAC keying namespaces the stored digest away from every other
 * credential hash in the database, exactly as the session key's does.
 */
final class SharedWorkstationSignInPickupSecret
{
    public const LENGTH = 40;

    /** Domain separation for the stored hash. */
    private const HASH_NAMESPACE = 'meridian.shared-workstation-sign-in-request.v1';

    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    public static function hash(string $secret): string
    {
        return hash_hmac(
            'sha256',
            self::HASH_NAMESPACE.'|'.$secret,
            (string) Config::get('app.key'),
        );
    }
}
