<?php

namespace App\Services\Shift;

use RuntimeException;

class UnscheduledShiftAdditionException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to add unscheduled staff to this shift.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept unscheduled staff additions.');
    }

    public static function shiftNotStarted(): self
    {
        return new self('Unscheduled staff can only be added after shift operations have started.');
    }

    public static function notEligibleTeamMember(): self
    {
        return new self('Staff must belong to the shift eligible team before unscheduled shift addition.');
    }

    public static function noDepartmentMembership(): self
    {
        return new self('Staff must belong to the shift department before unscheduled shift addition.');
    }

    public static function doNotStaff(): self
    {
        return new self('Do Not Staff records cannot be added to shifts.');
    }

    public static function departmentIneligible(): self
    {
        return new self('Ineligible department status prevents unscheduled shift addition.');
    }

    public static function missingRequiredTraining(): self
    {
        return new self('Required training must be complete before unscheduled shift addition.');
    }

    public static function missingRequiredWaiver(): self
    {
        return new self('Required waiver must be complete before unscheduled shift addition.');
    }

    public static function alreadyAssigned(): self
    {
        return new self('This staff member is already assigned to the shift.');
    }

    public static function staffNotOnSite(): self
    {
        return new self('Staff must be marked on-site with this department before unscheduled shift addition.');
    }
}
