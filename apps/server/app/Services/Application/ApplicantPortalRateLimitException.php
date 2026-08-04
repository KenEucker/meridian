<?php

namespace App\Services\Application;

use RuntimeException;

/**
 * A portal link request refused by one of the APP-015 limits.
 *
 * The message says nothing about the address it was asked for, because a
 * refusal that varied would be the disclosure the responses are shaped to
 * avoid (APP-014).
 */
class ApplicantPortalRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $availableInSeconds = 0)
    {
        parent::__construct('Too many link requests from here. Try again later.');
    }
}
