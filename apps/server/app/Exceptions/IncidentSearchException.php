<?php

namespace App\Exceptions;

use RuntimeException;

class IncidentSearchException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
