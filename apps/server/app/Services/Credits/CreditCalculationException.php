<?php

declare(strict_types=1);

namespace App\Services\Credits;

use Illuminate\Support\Carbon;
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

    /**
     * The refusal for a run started before the configured grace period has
     * elapsed (ORG-017; CREDIT-001). Names the closing date in the event's own
     * time zone, the same way the hours correction refusal does.
     */
    public static function gracePeriodStillOpen(Carbon $closesAt, ?string $timeZone = null): self
    {
        return new self(sprintf(
            'Credits cannot be calculated before the correction grace period closes on %s.',
            $closesAt->copy()->setTimezone($timeZone ?: config('app.timezone'))->format('j M Y H:i T'),
        ));
    }
}
