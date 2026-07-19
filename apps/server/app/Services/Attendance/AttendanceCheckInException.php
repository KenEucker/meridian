<?php

namespace App\Services\Attendance;

use RuntimeException;

class AttendanceCheckInException extends RuntimeException
{
    public static function invalidOperationUuid(): self
    {
        return new self('Attendance operation UUID must be a valid UUID.');
    }

    public static function operationUuidConflict(): self
    {
        return new self('Attendance operation UUID was already used for different check-in data.');
    }

    public static function unauthorized(): self
    {
        return new self('You are not authorized to check staff in for this shift.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept attendance check-in.');
    }

    public static function noActiveAssignment(): self
    {
        return new self('Staff must have an active assignment for this shift before check-in.');
    }

    public static function staffNotOnSite(): self
    {
        return new self('Staff must be marked on-site with this department before shift check-in.');
    }
}
