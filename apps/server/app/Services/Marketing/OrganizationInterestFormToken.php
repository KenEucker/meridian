<?php

namespace App\Services\Marketing;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * A token proving that a submission came from somebody who opened the page
 * (M18.23; PUBLIC-005).
 *
 * The marketing surface is a client application view, so the timing signal the
 * server-rendered form would have taken from a session is not available: the
 * submission arrives as an API request carrying no session, and a duration the
 * client reported about itself is a number the client chose.
 *
 * So the node issues the timestamp instead. It goes out with the availability
 * read the surface makes before it renders, comes back with the submission, and
 * is encrypted with the application key — which is authenticated encryption, so
 * a client can neither read the timestamp nor move it.
 *
 * That buys two things. A submission that arrives faster than a person could
 * type is visible, which is the ordinary timing trap. And a client that posts
 * straight at the endpoint without ever asking for the page has no token at
 * all, which is the more valuable half: it is the shape almost every automated
 * submission takes.
 *
 * Neither is a challenge. Nobody is asked to identify a bus.
 */
class OrganizationInterestFormToken
{
    /**
     * How long an issued token stays usable.
     *
     * A day, because a page left open overnight is an ordinary thing a person
     * does and coming back to it should not lose their message. Past that the
     * surface asks them to reload, which is honest: the tab has outlived the
     * deployment it was served by often enough.
     */
    private const MAX_AGE_SECONDS = 86400;

    public function issue(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * How long ago this token was issued, or null if it is not a token this
     * node issued recently.
     *
     * Null covers absent, tampered with, malformed, expired, and issued in the
     * future — the last because a node whose clock moved backwards should not
     * hand out a token that reads as arbitrarily old.
     */
    public function ageInSeconds(?string $token): ?int
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $issuedAt = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        if (! ctype_digit($issuedAt)) {
            return null;
        }

        $age = now()->getTimestamp() - Carbon::createFromTimestamp((int) $issuedAt)->getTimestamp();

        if ($age < 0 || $age > self::MAX_AGE_SECONDS) {
            return null;
        }

        return $age;
    }
}
