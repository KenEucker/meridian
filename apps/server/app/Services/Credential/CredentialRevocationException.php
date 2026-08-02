<?php

namespace App\Services\Credential;

use RuntimeException;

/**
 * Why a revocation was refused, and with what standing.
 *
 * The two refusals are not the same kind of answer, and M18.5 is where that
 * starts to matter, because a screen now prints them. "You are not authorized"
 * is the node declining the caller and is a 403; "there is nothing here to
 * revoke" is the node accepting the caller and declining the request, and is a
 * 422 like every other command refusal. The status travels with the exception
 * so a controller reports that distinction rather than inventing it.
 */
class CredentialRevocationException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self('You are not authorized to revoke event credentials.', 403);
    }

    public static function noCredentialHistory(): self
    {
        return new self('This staff member has no event credential or shift history to revoke.', 422);
    }
}
