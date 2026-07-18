<?php

namespace App\Services\Attendance;

use RuntimeException;

class HoursCorrectionException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to correct hours for this shift.');
    }

    public static function invalidActualTimeRange(): self
    {
        return new self('Actual end time must be after actual start time.');
    }

    public static function frozenHours(): self
    {
        return new self('Hours are frozen after the correction grace period.');
    }

    public static function operationUuidConflict(): self
    {
        return new self('Attendance operation UUID was already used for different correction data.');
    }

    public static function invalidOperationUuid(): self
    {
        return new self('Attendance correction operation UUID must be a valid UUID.');
    }
}
