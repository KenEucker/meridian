<?php

namespace App\Services\Shift;

use RuntimeException;

class ShiftAssignmentException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to assign staff to this shift.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept assignment.');
    }

    public static function signupClosed(): self
    {
        return new self('Shift signup is not currently open.');
    }

    public static function notEligibleTeamMember(): self
    {
        return new self('Staff must belong to the shift eligible team before assignment.');
    }

    public static function noDepartmentMembership(): self
    {
        return new self('Staff must belong to the shift department before assignment.');
    }

    public static function doNotStaff(): self
    {
        return new self('Do Not Staff records cannot be assigned to shifts.');
    }

    public static function alreadyAssigned(): self
    {
        return new self('This staff member is already assigned to the shift.');
    }
}
