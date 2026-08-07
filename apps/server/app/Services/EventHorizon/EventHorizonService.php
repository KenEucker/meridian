<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Domain\EventHorizon\EventHorizonCatalog;
use App\Domain\EventHorizon\EventHorizonItem;
use App\Domain\EventHorizon\EventHorizonItemState;
use App\Models\Event;
use App\Models\EventHorizonDismissal;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Compiles one staff member's readiness for one event (M18.38; HORIZON-001
 * through HORIZON-008; technical spec 21D).
 *
 * The whole view compiles on read and nothing about it is stored: no table
 * holds a compiled item, an outstanding count, or a readiness state, for the
 * same reason 21C.8 keeps compiled metric values out of the database — a
 * stored answer to a question the source data can answer is a second copy that
 * goes wrong quietly (21D.3).
 *
 * Each registered kind is evaluated under the viewer's existing authorization
 * for the domain it reads. A kind the viewer cannot read is omitted entirely
 * rather than reported as unknown (21D.5), and a kind is registered here in
 * code and nowhere else — there is no configuration path that could add,
 * remove, or reorder one (HORIZON-003).
 *
 * The service enforces nothing (HORIZON-008). Every condition it reports is
 * enforced, or deliberately not enforced, by the rule that already governs it;
 * the one refusal in this file is the personal-dismissal guard (HORIZON-013),
 * which guards the preference rather than any operational record.
 */
final class EventHorizonService
{
    public function __construct(
        private readonly EventHorizonViewerResolver $viewers,
    ) {}

    public function viewerFor(User $user, Event $event): EventHorizonViewer
    {
        return $this->viewers->resolve($user, $event);
    }

    /**
     * The registered kinds this viewer can read, in catalogue order.
     *
     * @return list<EventHorizonItemKind>
     */
    public function availableKinds(EventHorizonViewer $viewer, Event $event): array
    {
        return array_values(array_filter(
            $this->kinds(),
            fn (EventHorizonItemKind $kind): bool => $kind->availableTo($viewer, $event),
        ));
    }

    /**
     * Every item on this viewer's list, in the HORIZON-006 order: outstanding
     * before complete, then soonest applicable deadline with undated items
     * after dated ones, then the fixed catalogue order, then the item's own
     * identity so the order cannot vary between two identical reads.
     *
     * @return list<EventHorizonItem>
     */
    public function compile(EventHorizonViewer $viewer, Event $event, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $items = [];

        foreach ($this->availableKinds($viewer, $event) as $kind) {
            foreach ($kind->compile($viewer, $event, $now) as $item) {
                $items[] = $item;
            }
        }

        usort($items, function (EventHorizonItem $left, EventHorizonItem $right): int {
            if ($left->state !== $right->state) {
                return $left->state === EventHorizonItemState::Outstanding ? -1 : 1;
            }

            if (($left->dueAt === null) !== ($right->dueAt === null)) {
                return $left->dueAt === null ? 1 : -1;
            }

            if ($left->dueAt !== null && $right->dueAt !== null && ! $left->dueAt->equalTo($right->dueAt)) {
                return $left->dueAt->lessThan($right->dueAt) ? -1 : 1;
            }

            $byCatalogue = EventHorizonCatalog::order($left->kind) <=> EventHorizonCatalog::order($right->kind);

            return $byCatalogue !== 0 ? $byCatalogue : strcmp($left->identity, $right->identity);
        });

        return $items;
    }

    /**
     * @param  list<EventHorizonItem>  $items
     */
    public function hasOutstanding(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->state === EventHorizonItemState::Outstanding) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the moment stands against the event's presentation window
     * (M18.38A; HORIZON-011; technical spec 21D.4).
     *
     * Both edges come from the event's active window — the lead-up is an
     * offset from its start, and the close is its end — so moving the event
     * moves the whole window and nothing has to be re-entered. An event with
     * no active window recorded has no moment to lead up to, and the surface
     * is not presented rather than guessed at.
     */
    public function windowFor(Event $event, ?Carbon $now = null): EventHorizonWindow
    {
        $now ??= Carbon::now();
        $leadDays = $event->organization?->eventHorizonLeadDays()
            ?? $event->organization()->first()?->eventHorizonLeadDays()
            ?? 30;

        $windowStart = $event->active_event_window_starts_at;
        $windowEnd = $event->active_event_window_ends_at;

        if ($windowStart === null) {
            return new EventHorizonWindow(false, EventHorizonWindow::REASON_NO_ACTIVE_WINDOW, $leadDays, null, null);
        }

        $opensAt = $windowStart->copy()->subDays($leadDays);

        if ($now->lessThan($opensAt)) {
            return new EventHorizonWindow(false, EventHorizonWindow::REASON_BEFORE_LEAD_UP, $leadDays, $opensAt, $windowEnd);
        }

        if ($windowEnd !== null && $now->greaterThan($windowEnd)) {
            return new EventHorizonWindow(false, EventHorizonWindow::REASON_CLOSED, $leadDays, $opensAt, $windowEnd);
        }

        return new EventHorizonWindow(true, EventHorizonWindow::REASON_OPEN, $leadDays, $opensAt, $windowEnd);
    }

    /**
     * Whether this viewer has hidden the surface for this event (HORIZON-012,
     * HORIZON-015).
     *
     * A dismissal does not survive a new outstanding item: when one is found
     * alongside a stored row, the row is discarded here — the surface returns,
     * and completing the new item later does not re-hide it, because the old
     * decision was about a list that no longer exists (data/API 10.21). This
     * is the preference following its own rule, not the read persisting
     * compiled state: no readiness data is written, ever.
     */
    public function isHidden(EventHorizonViewer $viewer, Event $event, bool $hasOutstanding): bool
    {
        if ($viewer->staffIds === []) {
            return false;
        }

        $rows = EventHorizonDismissal::query()
            ->where('event_id', $event->getKey())
            ->whereIn('staff_id', $viewer->staffIds);

        if (! $rows->exists()) {
            return false;
        }

        if ($hasOutstanding) {
            $rows->delete();

            return false;
        }

        return true;
    }

    /**
     * Hide the surface for this viewer and event (HORIZON-012, HORIZON-013).
     *
     * Refused while anything is outstanding, on the node rather than in the
     * interface: a client that offers the control early does not succeed
     * (technical spec 21D.8). Acts only on the caller's own preference and is
     * not audited (21D.10) — personal view state is not a record of anything
     * operational.
     *
     * @throws EventHorizonDismissalRefusedException
     */
    public function hide(EventHorizonViewer $viewer, Event $event, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();

        if (! $viewer->isEventStaff()) {
            throw new EventHorizonDismissalRefusedException(
                'Only a staff member of this event can hide its Event Horizon.',
            );
        }

        if ($this->hasOutstanding($this->compile($viewer, $event, $now))) {
            throw new EventHorizonDismissalRefusedException(
                'The Event Horizon cannot be hidden while an item is outstanding. Finish your items first.',
            );
        }

        foreach ($viewer->staffIds as $staffId) {
            EventHorizonDismissal::query()->firstOrCreate(
                ['staff_id' => $staffId, 'event_id' => (string) $event->getKey()],
                ['dismissed_at' => $now],
            );
        }
    }

    /**
     * Restore the surface (HORIZON-015): deleting the row rather than writing
     * a second state, because "not hidden" is the absence of a decision.
     */
    public function show(EventHorizonViewer $viewer, Event $event): void
    {
        if ($viewer->staffIds === []) {
            return;
        }

        EventHorizonDismissal::query()
            ->where('event_id', $event->getKey())
            ->whereIn('staff_id', $viewer->staffIds)
            ->delete();
    }

    /**
     * The fixed Alpha 1 registry (HORIZON-003). Five kinds, in catalogue
     * order, constructed here rather than injected: they hold no state worth
     * sharing between requests, and a container binding would be a seam
     * something could re-register through.
     *
     * @return list<EventHorizonItemKind>
     */
    private function kinds(): array
    {
        return [
            new DocumentAcknowledgmentItems,
            new WaiverItems,
            new TrainingItems,
            new ShiftSignupItems,
            new CoverageGapItems,
        ];
    }
}
