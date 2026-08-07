<?php

declare(strict_types=1);

namespace App\Services\DepartmentOps;

use Illuminate\Support\Carbon;

/**
 * How far either side of now a Logistics desk reaches (SLB-003, SLB-021).
 *
 * A desk is a service station for the shift in front of it: the one running, the
 * one about to start, and the one that just ended and still has people to check
 * out and equipment to take back. Twelve hours back and thirty-six forward
 * covers an overnight handover without handing a desk every shift of a ten-day
 * event.
 *
 * It lives here rather than on the read that first needed it because the offline
 * read set composes the same index (M18.47; technical spec 9.3). A device that
 * cached a wider horizon than its surface renders would be carrying rows nothing
 * displays, and a narrower one would show less offline than online — both are
 * what one shared constant prevents.
 */
final class DeskHorizon
{
    public const HOURS_BEFORE = 12;

    public const HOURS_AFTER = 36;

    /**
     * The earliest a shift may end and still be at the desk.
     */
    public static function endsAfter(Carbon $now): Carbon
    {
        return $now->copy()->subHours(self::HOURS_BEFORE);
    }

    /**
     * The latest a shift may start and still be at the desk.
     */
    public static function startsBefore(Carbon $now): Carbon
    {
        return $now->copy()->addHours(self::HOURS_AFTER);
    }
}
