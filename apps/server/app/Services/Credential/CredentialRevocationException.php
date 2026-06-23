<?php

namespace App\Services\Credential;

use RuntimeException;

class CredentialRevocationException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to revoke event credentials.');
    }

    public static function noCredentialHistory(): self
    {
        return new self('This staff member has no event credential or shift history to revoke.');
    }
}
