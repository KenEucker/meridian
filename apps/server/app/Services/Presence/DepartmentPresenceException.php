<?php

namespace App\Services\Presence;

use RuntimeException;

class DepartmentPresenceException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to manage department presence.');
    }

    public static function departmentOutsideEventOrganization(): self
    {
        return new self('Department must belong to the same organization as the event.');
    }

    public static function staffNotEligibleForDepartment(): self
    {
        return new self('Staff must be an active department member before being marked on-site.');
    }

    public static function checkedInToShift(): self
    {
        return new self('Staff must be checked out from department shifts before being marked off-site.');
    }

    public static function openEquipmentCheckout(): self
    {
        return new self('Staff must return or resolve checked-out department equipment before being marked off-site.');
    }
}
