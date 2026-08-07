<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Domain\EventHorizon\EventHorizonItem;
use App\Domain\EventHorizon\EventHorizonItemKindDefinition;
use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * One registered item kind (HORIZON-003; technical spec 21D.2).
 *
 * The registration contract is: given an event and a staff member, return that
 * kind's items. Two rules bind every implementation:
 *
 *  1. **Read only what the viewer already reads** (HORIZON-002, 21D.5). Each
 *     kind is evaluated under the viewer's existing authorization for the
 *     domain it draws on, and a kind whose records the viewer cannot read
 *     reports itself unavailable — the whole kind is then absent from the
 *     response rather than empty or "unknown", because "there is something
 *     here you cannot see" is itself a disclosure.
 *  2. **Enforce nothing** (HORIZON-008). A kind restates the evaluation of the
 *     rule that already governs its records; it never refuses an operation,
 *     writes a record, or carries a threshold of its own.
 */
abstract class EventHorizonItemKind
{
    abstract public function definition(): EventHorizonItemKindDefinition;

    /**
     * Whether this viewer can read the records behind the kind (21D.5).
     */
    abstract public function availableTo(EventHorizonViewer $viewer, Event $event): bool;

    /**
     * This kind's items for this viewer and event, unordered — ordering across
     * kinds is the service's (HORIZON-006).
     *
     * @return list<EventHorizonItem>
     */
    abstract public function compile(EventHorizonViewer $viewer, Event $event, Carbon $now): array;
}
