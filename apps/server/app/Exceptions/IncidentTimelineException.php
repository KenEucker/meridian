<?php

namespace App\Exceptions;

use RuntimeException;

final class IncidentTimelineException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
