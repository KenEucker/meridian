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

    public static function conflictingScheduleLock(): self
    {
        return new self('A schedule cutoff is either an absolute time or an offset before the event window, not both.');
    }

    public static function invalidScheduleLockOffset(): self
    {
        return new self('A relative schedule cutoff must be at least one minute before the event window start.');
    }
}
