<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Event;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Reads the timestamps a shift or assignment import file carries (technical
 * spec 22.2).
 *
 * A plain `2026-08-28 09:00` is read in the event's own timezone, because an
 * operator building a schedule in a spreadsheet writes the times the shift will
 * actually be worked, not UTC. A value that states its own offset — `Z`, or
 * `+02:00` — is honored as written, so an export from another system round
 * trips without being shifted twice.
 *
 * Everything is converted to UTC before it reaches the domain, which is the
 * only form the database stores.
 */
final class ImportMoment
{
    /**
     * @return Carbon|null Null when the cell is empty or is not a date at all.
     */
    public static function parse(string $value, Event $event): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $event->timezone)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
