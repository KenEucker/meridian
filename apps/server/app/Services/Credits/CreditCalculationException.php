<?php

declare(strict_types=1);

namespace App\Services\Credits;

use RuntimeException;

class CreditCalculationException extends RuntimeException
{
    public static function hoursNotFinalized(int $openRecordCount): self
    {
        return new self(sprintf(
            'Credits cannot be calculated while the correction grace period is open: %d hours record(s) are not frozen.',
            $openRecordCount,
        ));
    }
}
