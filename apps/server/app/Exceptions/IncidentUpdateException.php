<?php

namespace App\Exceptions;

use DomainException;

final class IncidentUpdateException extends DomainException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
