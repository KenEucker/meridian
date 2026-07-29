<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use InvalidArgumentException;

/**
 * A proposed or stored override value that does not satisfy its catalogue
 * entry's declared type (SYS-006, SYS-007). Messages are operator-facing and
 * never contain secret values.
 */
class InvalidSystemConfigValue extends InvalidArgumentException
{
    public static function forType(string $name, string $type, string $problem): self
    {
        return new self("The value for {$name} is not a valid {$type}: {$problem}");
    }
}
