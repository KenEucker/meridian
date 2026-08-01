<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

/**
 * The one "now" every development seeder anchors to.
 *
 * The seed used to place its event four months out, which meant no shift was
 * ever running and the Logistics Desk, the Operations Center, and the Planning
 * Table all opened onto an empty department. Nothing about check-in, check-out,
 * hours correction, unscheduled addition, or deployment could be exercised
 * without hand-writing rows first.
 *
 * So the operational scenario is built relative to the moment it is seeded, and
 * every seeder reads its anchors from here rather than calling `now()` for
 * itself. One clock for the whole run matters: a seed that takes eleven seconds
 * would otherwise place the shift and the check-in against two different
 * "nows", and a check-in stamped a second before the shift it belongs to is the
 * sort of off-by-a-hair data that makes a surface look broken when it is not.
 *
 * Everything is floored to the minute. Seconds carry no meaning here and their
 * only effect is to make screenshots and tinker output harder to compare.
 */
final class ScenarioClock
{
    private static ?Carbon $now = null;

    /**
     * The instant the current seed run is anchored to.
     *
     * Held for the life of the process rather than recomputed, so the whole
     * scenario agrees on what time it is.
     */
    public static function now(): Carbon
    {
        return self::$now ??= Carbon::now()->startOfMinute();
    }

    /** Forget the anchor, so a second run inside one process re-anchors. */
    public static function reset(): void
    {
        self::$now = null;
    }

    public static function hoursAgo(int|float $hours): Carbon
    {
        return self::now()->copy()->subMinutes((int) round($hours * 60));
    }

    public static function hoursFromNow(int|float $hours): Carbon
    {
        return self::now()->copy()->addMinutes((int) round($hours * 60));
    }

    public static function daysAgo(int $days): Carbon
    {
        return self::now()->copy()->subDays($days);
    }

    public static function daysFromNow(int $days): Carbon
    {
        return self::now()->copy()->addDays($days);
    }
}
