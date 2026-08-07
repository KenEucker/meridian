<?php

declare(strict_types=1);

namespace App\Domain\EventHorizon;

/**
 * The two states an Event Horizon item can be in (HORIZON-003; technical spec
 * 21D.2).
 *
 * There is deliberately no third state. An item is outstanding or it is
 * complete, and it moves between the two only because the record behind it
 * changed (HORIZON-007) — no dismissed, no snoozed, no acknowledged, no
 * in-progress. A kind that cannot be evaluated is omitted or disclosed as
 * unevaluated, never given a state it has not established.
 */
enum EventHorizonItemState: string
{
    case Outstanding = 'outstanding';
    case Complete = 'complete';
}
