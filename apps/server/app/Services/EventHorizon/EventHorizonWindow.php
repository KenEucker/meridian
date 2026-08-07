<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use Illuminate\Support\Carbon;

/**
 * Where the moment stands against one event's presentation window (M18.38A;
 * HORIZON-010, HORIZON-011; technical spec 21D.4).
 *
 * The Event Horizon is presented from a lead-up window before the event's
 * active window start through the close of the operations window. The window
 * is derived on read from the organization's configured lead length and the
 * event's own dates — held nowhere, so moving an event moves the window with
 * it.
 *
 * `applies` false is not a refusal and not a 404: the read answers with the
 * same shape and this reason, so a client can tell "not yet" from "no such
 * event" (data/API 5.8A).
 */
final class EventHorizonWindow
{
    public const REASON_OPEN = 'open';

    public const REASON_BEFORE_LEAD_UP = 'before_lead_up';

    public const REASON_CLOSED = 'closed';

    /** The event has no active window recorded, so there is no moment to lead up to. */
    public const REASON_NO_ACTIVE_WINDOW = 'no_active_window';

    public function __construct(
        public readonly bool $applies,
        public readonly string $reason,
        public readonly int $leadDays,
        public readonly ?Carbon $opensAt,
        public readonly ?Carbon $closesAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applies' => $this->applies,
            'reason' => $this->reason,
            'lead_days' => $this->leadDays,
            'opens_at' => $this->opensAt?->toIso8601String(),
            'closes_at' => $this->closesAt?->toIso8601String(),
        ];
    }
}
