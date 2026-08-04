<?php

declare(strict_types=1);

namespace App\Services\Credits;

use RuntimeException;

/**
 * A refusal an organizer is shown while maintaining credit policies.
 */
final class CreditPolicyAdminException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
