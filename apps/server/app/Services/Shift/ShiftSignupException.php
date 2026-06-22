<?php

namespace App\Services\Shift;

use RuntimeException;

class ShiftSignupException extends RuntimeException
{
    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept signup.');
    }

    public static function signupClosed(): self
    {
        return new self('Shift signup is not currently open.');
    }

    public static function scheduleLocked(): self
    {
        return new self('The schedule is locked and cannot be changed.');
    }

    public static function notEligibleTeamMember(): self
    {
        return new self('Staff must belong to the shift eligible team before signup.');
    }

    public static function noDepartmentMembership(): self
    {
        return new self('Staff must belong to the shift department before signup.');
    }

    public static function doNotStaff(): self
    {
        return new self('Do Not Staff records cannot sign up for shifts.');
    }

    public static function alreadySignedUp(): self
    {
        return new self('This staff member is already signed up for the shift.');
    }

    public static function staffNotLinkedToUser(): self
    {
        return new self('The staff profile must belong to the signing-up user.');
    }

    public static function departmentIneligible(): self
    {
        return new self('Ineligible department status prevents shift signup.');
    }

    public static function missingRequiredTraining(): self
    {
        return new self('Required training must be complete before shift signup.');
    }

    public static function missingRequiredWaiver(): self
    {
        return new self('Required waiver must be complete before shift signup.');
    }

    public static function shiftFull(): self
    {
        return new self('This shift is full and cannot accept additional signup.');
    }
}
