<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;
use RuntimeException;

class HoursCorrectionException extends RuntimeException
{
    public static function unauthorized(): self
    {
        return new self('You are not authorized to correct hours for this shift.');
    }

    public static function invalidActualTimeRange(): self
    {
        return new self('Actual end time must be after actual start time.');
    }

    /**
     * The refusal a frozen record answers a correction with (HOURS-008,
     * SLB-031).
     *
     * It names when the grace period closed rather than stating that one
     * exists. "Hours are frozen after the correction grace period" is a rule,
     * and an operator standing at a desk with a staff member in front of them
     * cannot act on a rule — they can act on a date, because it tells them
     * whether they are an hour late or a month late and therefore whether this
     * is worth escalating to an organizer at all.
     *
     * The date is read in the event's own time zone. A grace period that closed
     * at midnight local reads as the previous evening in UTC, and a desk asking
     * "closed when?" means local midnight.
     */
    public static function frozenHours(?Carbon $frozenAt = null, ?string $timeZone = null): self
    {
        if ($frozenAt === null) {
            return new self('Hours are frozen because the correction grace period has closed, and can no longer be corrected.');
        }

        return new self(sprintf(
            'The correction grace period closed on %s, so these hours are frozen and can no longer be corrected.',
            $frozenAt->copy()->setTimezone($timeZone ?: config('app.timezone'))->format('j M Y H:i T'),
        ));
    }

    public static function operationUuidConflict(): self
    {
        return new self('Attendance operation UUID was already used for different correction data.');
    }

    public static function invalidOperationUuid(): self
    {
        return new self('Attendance correction operation UUID must be a valid UUID.');
    }
}
