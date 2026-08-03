<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * When an event's hours correction window closes (M18.14; ORG-017).
 *
 * The window is the organization's configured grace period — days after event
 * end, defaulting to 14 — during which authorized attendance managers may
 * correct hours (HOURS-007) and after which hours freeze (HOURS-008). One
 * answer shared by the correction command, the credit calculation gate, and
 * the Logistics Desk read, so all three name the same closing moment.
 *
 * The close is computed rather than stored. `hours_worked.frozen_at` remains
 * the per-record freeze marker the credit ledger reads, but a record nobody
 * has frozen yet is still past correcting once the configured period has
 * elapsed — the window is the rule, the marker is the receipt.
 */
final class HoursCorrectionWindow
{
    /**
     * The moment this event's correction window closes, or null while the
     * event has no end to count from.
     */
    public function closesAt(Event $event): ?Carbon
    {
        if ($event->ends_at === null) {
            return null;
        }

        $event->loadMissing('organization');

        $graceDays = $event->organization?->hoursCorrectionGracePeriodDays() ?? 14;

        return Carbon::instance($event->ends_at)->addDays($graceDays);
    }

    public function hasClosed(Event $event, ?Carbon $asOf = null): bool
    {
        $closesAt = $this->closesAt($event);

        return $closesAt !== null && ($asOf ?? Carbon::now())->greaterThanOrEqualTo($closesAt);
    }
}
