<?php

namespace App\Services\Equipment;

use RuntimeException;

class EquipmentCheckoutException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to manage equipment for this scope.');
    }

    public static function cancelledShift(): self
    {
        return new self('Cancelled shifts do not accept equipment checkout changes.');
    }

    public static function archivedEquipment(): self
    {
        return new self('Archived equipment cannot be checked out.');
    }

    public static function equipmentOutsideShiftScope(): self
    {
        return new self('Equipment must belong to the same event or department as the shift when those equipment scopes are set.');
    }

    public static function noEventContext(): self
    {
        return new self('Equipment checkout requires an event context.');
    }

    public static function staffNotRosteredForShift(): self
    {
        return new self('Equipment checkout from the Shift Lead Board requires an active shift assignment for the staff member.');
    }

    public static function staffOutsideDepartment(): self
    {
        return new self('Equipment checkout requires a staff member in the equipment department.');
    }

    public static function equipmentUnavailable(string $status): self
    {
        return new self("Equipment with status {$status} cannot be checked out.");
    }

    public static function openCheckoutForDifferentStaff(): self
    {
        return new self('Equipment is already checked out to another staff member.');
    }

    public static function invalidReturnCondition(): self
    {
        return new self('Equipment return condition must be returned, missing, or damaged.');
    }

    public static function checkoutAlreadyReturned(): self
    {
        return new self('Equipment checkout has already been returned with a different condition.');
    }

    public static function returnBeforeCheckout(): self
    {
        return new self('Equipment cannot be returned before it was checked out.');
    }

    public static function invalidQuantity(): self
    {
        return new self('Equipment checkout quantity must be at least one unit.');
    }

    public static function trackedQuantityMustBeOne(): self
    {
        return new self('An individually tracked item is one unit; check out the unit rather than a quantity.');
    }

    public static function insufficientPoolQuantity(string $name, int $available, int $requested): self
    {
        return new self(
            "Only {$available} of \"{$name}\" are available to hand out; {$requested} were requested.",
        );
    }

    public static function returnExceedsOutstanding(int $outstanding, int $requested): self
    {
        return new self(
            "This checkout has {$outstanding} unit(s) still out; {$requested} were offered back.",
        );
    }
}
