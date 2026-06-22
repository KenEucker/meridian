<?php

namespace App\Services\Shift;

use RuntimeException;

class ShiftRemovalException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to remove staff from this shift.');
    }

    public static function scheduleLocked(): self
    {
        return new self('The schedule is locked and cannot be changed.');
    }

    public static function alreadyRemoved(): self
    {
        return new self('This shift assignment has already been removed.');
    }

    public static function staffNotLinkedToUser(): self
    {
        return new self('The staff profile must belong to the withdrawing user.');
    }

    public static function assignmentMismatch(): self
    {
        return new self('The assignment does not belong to the withdrawing staff member.');
    }
}
