<?php

namespace App\Services\Attendance;

use RuntimeException;

class AttendanceMarkNoShowException extends RuntimeException
{
    public static function invalidOperationUuid(): self
    {
        return new self('Attendance operation UUID must be a valid UUID.');
    }

    public static function operationUuidConflict(): self
    {
        return new self('Attendance operation UUID was already used for different no-show data.');
    }

    public static function unauthorized(): self
    {
        return new self('You are not authorized to mark staff as no-show for this shift.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept no-show attendance operations.');
    }

    public static function noActiveAssignment(): self
    {
        return new self('Staff must have an active assignment for this shift before being marked no-show.');
    }

    public static function shiftNotStarted(): self
    {
        return new self('Staff can only be marked no-show after the shift has started.');
    }

    public static function conflictingState(): self
    {
        return new self('Staff cannot be marked no-show after check-in or check-out attendance has been recorded.');
    }
}
