<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use RuntimeException;

/**
 * A refusal an organizer is shown while maintaining the incident type list.
 */
final class IncidentTypeAdminException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
