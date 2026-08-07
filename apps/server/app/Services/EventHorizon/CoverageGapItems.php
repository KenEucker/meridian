<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Domain\EventHorizon\EventHorizonCatalog;
use App\Domain\EventHorizon\EventHorizonItem;
use App\Domain\EventHorizon\EventHorizonItemKindDefinition;
use App\Domain\EventHorizon\EventHorizonItemState;
use App\Models\Event;
use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Team coverage gaps, for leads (M18.42; HORIZON-003, HORIZON-009; SHIFT-007;
 * requirements 4.7).
 *
 * The one kind that reads beyond the viewer's own records, and it reads only
 * teams the viewer leads (technical spec 21D.5): shifts in this event for
 * those teams that carry a capacity and have not filled it. A member who leads
 * no team gets no kind at all — omitted, not empty — because the records
 * behind it are not theirs to read.
 *
 * The item reports the shortfall against capacity and **carries no staff
 * names** (HORIZON-009). Who is assigned is read on the shift surface the item
 * links to, where that authority already lives; a readiness card that named
 * the people missing from a roster would be a disclosure wearing a number.
 *
 * A capacity-carrying shift that has filled reads complete rather than
 * disappearing (HORIZON-005), so a lead who worked a gap down to zero can see
 * that it registered. Shifts that already ended report nothing: there is no
 * readiness left in them.
 */
final class CoverageGapItems extends EventHorizonItemKind
{
    public function definition(): EventHorizonItemKindDefinition
    {
        return EventHorizonCatalog::definitions()[4];
    }

    /**
     * Available only to somebody who leads a team (HORIZON-009): for everyone
     * else the kind is absent rather than empty, because "your teams have no
     * gaps" is not a true sentence about somebody with no teams.
     */
    public function availableTo(EventHorizonViewer $viewer, Event $event): bool
    {
        return $viewer->isEventStaff() && $viewer->ledTeamIds !== [];
    }

    /**
     * @return list<EventHorizonItem>
     */
    public function compile(EventHorizonViewer $viewer, Event $event, Carbon $now): array
    {
        $shifts = Shift::query()
            ->where('event_id', $event->id)
            ->whereIn('eligible_team_id', $viewer->ledTeamIds)
            ->whereNotNull('capacity')
            ->active()
            ->withCount('activeAssignments')
            ->orderBy('starts_at')
            ->get();

        $items = [];

        foreach ($shifts as $shift) {
            // A shift that has ended has no coverage left to worry about.
            if ($shift->ends_at !== null && $shift->ends_at->lessThanOrEqualTo($now)) {
                continue;
            }

            $assigned = (int) ($shift->active_assignments_count ?? 0);
            $shortfall = max(0, (int) $shift->capacity - $assigned);

            $items[] = new EventHorizonItem(
                kind: $this->definition()->id,
                identity: 'coverage-gap:'.(string) $shift->getKey(),
                state: $shortfall > 0
                    ? EventHorizonItemState::Outstanding
                    : EventHorizonItemState::Complete,
                title: (string) $shift->title,
                evaluation: $shortfall > 0
                    ? sprintf(
                        'This shift for a team you lead has %d of %d places unfilled.',
                        $shortfall,
                        (int) $shift->capacity,
                    )
                    : sprintf('This shift is fully staffed at %d.', (int) $shift->capacity),
                completion: $shortfall > 0
                    ? 'Fill the shift — who is assigned is read on the shift itself.'
                    : 'Nothing — the shift is covered.',
                dueAt: $shift->starts_at !== null && $shift->starts_at->greaterThan($now)
                    ? $shift->starts_at
                    : null,
                actionSurface: 'department.shifts',
                actionLabel: 'Open the shift',
                actionParams: [
                    'department_id' => (string) $shift->department_id,
                    'shift_id' => (string) $shift->getKey(),
                ],
            );
        }

        return $items;
    }
}
