<?php

namespace App\Services\Attendance;

use RuntimeException;

class AttendanceCheckOutException extends RuntimeException
{
    public static function invalidOperationUuid(): self
    {
        return new self('Attendance operation UUID must be a valid UUID.');
    }

    public static function operationUuidConflict(): self
    {
        return new self('Attendance operation UUID was already used for different check-out data.');
    }

    public static function unauthorized(): self
    {
        return new self('You are not authorized to check staff out for this shift.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept attendance check-out.');
    }

    public static function noActiveAssignment(): self
    {
        return new self('Staff must have an active assignment for this shift before check-out.');
    }

    public static function missingActualStart(): self
    {
        return new self('Check-out requires a prior check-in time or supplied actual start time.');
    }

    public static function invalidActualTimeRange(): self
    {
        return new self('Actual end time must be after actual start time.');
    }

    public static function alreadyCheckedOut(): self
    {
        return new self('Staff is already checked out for this shift.');
    }
}
