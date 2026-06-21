<?php

namespace App\Services\Waiver;

use InvalidArgumentException;

class WaiverScopeException extends InvalidArgumentException
{
    public static function unsupportedScopeType(): self
    {
        return new self('Waivers may only be assigned at organization, department, or team level.');
    }

    public static function invalidScopeTarget(): self
    {
        return new self('The scope target must belong to the waiver organization and scope type.');
    }
}
