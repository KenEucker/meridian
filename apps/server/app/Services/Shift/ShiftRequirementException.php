<?php

namespace App\Services\Shift;

use RuntimeException;

class ShiftRequirementException extends RuntimeException
{
    public static function differentOrganization(): self
    {
        return new self('The requirement must belong to the same organization as the shift event.');
    }

    public static function duplicateTraining(): self
    {
        return new self('This training requirement already exists for the shift.');
    }

    public static function duplicateWaiver(): self
    {
        return new self('This waiver requirement already exists for the shift.');
    }

    public static function invalidSignupWindow(): self
    {
        return new self('Signup close must be after signup open when both dates are configured.');
    }
}
