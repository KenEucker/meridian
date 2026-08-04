<?php

namespace App\Services\Marketing;

use RuntimeException;

/**
 * An organization interest submission refused by one of the PUBLIC-005 limits.
 *
 * The message is about the requester rather than about the submission, because
 * a limit that described the record would tell an automated client which of its
 * attempts landed.
 */
class OrganizationInterestRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $availableInSeconds = 0)
    {
        parent::__construct('Too many submissions from here. Try again later, and thank you for your patience.');
    }
}
