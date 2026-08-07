<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Domain\EventHorizon\EventHorizonCatalog;
use App\Domain\EventHorizon\EventHorizonItem;
use App\Domain\EventHorizon\EventHorizonItemKindDefinition;
use App\Domain\EventHorizon\EventHorizonItemState;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Illuminate\Support\Carbon;

/**
 * Shift signup opportunities (M18.41; HORIZON-003, HORIZON-006; SHIFT-004,
 * SHIFT-007, SHIFT-008, SHIFT-011, SHIFT-017, SHIFT-018).
 *
 * Shifts in this event open to a team the member is on that still have room:
 *
 *  - a shift the member already holds reads **complete** rather than
 *    disappearing — being on it is the readiness the item reports, and it
 *    stays reported after the cutoff closes;
 *  - a full shift produces no item: there is nothing outstanding about a shift
 *    that cannot take you, and a list that named it would be a list of other
 *    people's plans;
 *  - a shift whose signup window or schedule cutoff has closed produces no
 *    item for the same reason.
 *
 * The deadline is the sooner of the signup close and the resolved schedule
 * cutoff (SHIFT-017), which is what orders these by how soon they stop being
 * available (HORIZON-006). Eligibility beyond team and capacity — trainings,
 * waivers, department status — is deliberately not re-derived here: the shift
 * board the item links to answers it with the command's own verdict
 * (SHIFT-018), and a second copy of that rule would be a second place for it
 * to be wrong. The item reports an open door; the board says whether you may
 * walk through it.
 */
final class ShiftSignupItems extends EventHorizonItemKind
{
    public function definition(): EventHorizonItemKindDefinition
    {
        return EventHorizonCatalog::definitions()[3];
    }

    /**
     * The board behind the item is the member's own memberships and their own
     * assignments (SHIFT-011), so standing is the gate.
     */
    public function availableTo(EventHorizonViewer $viewer, Event $event): bool
    {
        return $viewer->isEventStaff();
    }

    /**
     * @return list<EventHorizonItem>
     */
    public function compile(EventHorizonViewer $viewer, Event $event, Carbon $now): array
    {
        if ($viewer->teamIds === []) {
            return [];
        }

        $shifts = Shift::query()
            ->where('event_id', $event->id)
            ->whereIn('eligible_team_id', $viewer->teamIds)
            ->active()
            ->with('event')
            ->withCount('activeAssignments')
            ->orderBy('starts_at')
            ->get();

        if ($shifts->isEmpty()) {
            return [];
        }

        $heldShiftIds = ShiftAssignment::query()
            ->active()
            ->whereIn('staff_id', $viewer->staffIds)
            ->whereIn('shift_id', $shifts->pluck('id')->all())
            ->pluck('shift_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $items = [];

        foreach ($shifts as $shift) {
            $held = in_array((string) $shift->getKey(), $heldShiftIds, true);

            if ($held) {
                $items[] = $this->item(
                    $shift,
                    EventHorizonItemState::Complete,
                    'You are signed up for this shift.',
                    'Nothing — you are on it.',
                    null,
                );

                continue;
            }

            // A shift that has already started is not a signup opportunity.
            if ($shift->starts_at !== null && $shift->starts_at->lessThanOrEqualTo($now)) {
                continue;
            }

            $atCapacity = $shift->capacity !== null
                && (int) ($shift->active_assignments_count ?? 0) >= $shift->capacity;

            if ($atCapacity || ! $shift->isSignupOpenAt($now) || $shift->isScheduleLockedAt($now)) {
                continue;
            }

            $closesAt = $this->closesAt($shift);
            $placesLeft = $shift->capacity === null
                ? null
                : $shift->capacity - (int) ($shift->active_assignments_count ?? 0);

            $items[] = $this->item(
                $shift,
                EventHorizonItemState::Outstanding,
                sprintf(
                    'This shift is open to your team%s%s.',
                    $placesLeft === null
                        ? ''
                        : sprintf(' with %d place%s left', $placesLeft, $placesLeft === 1 ? '' : 's'),
                    $closesAt === null
                        ? ''
                        : sprintf(', and signup closes %s', $closesAt->toDayDateTimeString()),
                ),
                'Sign up from the shift board, or decide you are not taking it.',
                $closesAt,
            );
        }

        return $items;
    }

    /**
     * The sooner of the signup close and the resolved schedule cutoff
     * (SHIFT-008, SHIFT-017) — after either, the door is shut.
     */
    private function closesAt(Shift $shift): ?Carbon
    {
        $candidates = array_filter([
            $shift->signup_closes_at,
            $shift->resolvedScheduleLockAt(),
            $shift->starts_at,
        ]);

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->min();
    }

    private function item(
        Shift $shift,
        EventHorizonItemState $state,
        string $evaluation,
        string $completion,
        ?Carbon $dueAt,
    ): EventHorizonItem {
        return new EventHorizonItem(
            kind: $this->definition()->id,
            identity: 'shift-signup:'.(string) $shift->getKey(),
            state: $state,
            title: (string) $shift->title,
            evaluation: $evaluation,
            completion: $completion,
            dueAt: $dueAt,
            actionSurface: 'staff.shift-board',
            actionLabel: 'Open the shift board',
        );
    }
}
