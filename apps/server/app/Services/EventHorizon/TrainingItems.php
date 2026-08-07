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
use App\Models\Team;
use App\Models\Training;
use App\Models\TrainingCompletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Required trainings (M18.40; HORIZON-003; TRAIN-002, TRAIN-008, TRAIN-009,
 * TRAIN-010, SHIFT-005).
 *
 * Two sources, merged: trainings scoped to a department or team the member
 * actively belongs to, and trainings required by shifts they hold in this
 * event (SHIFT-005). A training reached both ways is one item, because it is
 * one thing to do.
 *
 * Incomplete and expired are distinguished in the evaluation (TRAIN-002): both
 * read outstanding — TRAIN-008 blocks shift signup on either — but the member
 * does different things about them, and an item that said "incomplete" about a
 * lapsed completion would send somebody to sit a course they think they never
 * took.
 *
 * The deadline, where there is one, is the start of the earliest held shift
 * that requires the training: that is the moment TRAIN-008 makes it matter. A
 * training required only by membership has no moment it is due by and carries
 * none.
 *
 * The action link lands on the training's own page (TRAIN-010): an online
 * training's page carries its URL, and an in-person one carries its scheduled
 * session and signup (TRAIN-009), which is why the label differs by delivery.
 */
final class TrainingItems extends EventHorizonItemKind
{
    public function definition(): EventHorizonItemKindDefinition
    {
        return EventHorizonCatalog::definitions()[2];
    }

    /**
     * Training completions are the viewer's own records (TRAIN-003), so
     * standing is the gate.
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
        $required = $this->requiredTrainings($viewer, $event);

        if ($required === []) {
            return [];
        }

        $trainingIds = array_keys($required);

        $completions = TrainingCompletion::query()
            ->whereIn('training_id', $trainingIds)
            ->whereIn('staff_id', $viewer->staffIds)
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy(fn (TrainingCompletion $completion): string => (string) $completion->training_id);

        $shiftDeadlines = $this->heldShiftDeadlines($viewer, $event, $trainingIds, $now);

        $items = [];

        foreach ($required as $trainingId => $training) {
            $latest = $completions->get($trainingId)?->first();
            $current = $latest !== null && ! $latest->isExpiredAt($now);
            $expired = $latest !== null && $latest->isExpiredAt($now);

            $items[] = new EventHorizonItem(
                kind: $this->definition()->id,
                identity: 'training:'.$trainingId,
                state: $current
                    ? EventHorizonItemState::Complete
                    : EventHorizonItemState::Outstanding,
                title: (string) $training->name,
                evaluation: match (true) {
                    $current => 'Your completion of this training is on record and current.',
                    $expired => sprintf(
                        'Your completion of this training expired %s. Required training blocks shift signup until renewed.',
                        $latest->expires_at?->toDateString() ?? 'earlier',
                    ),
                    default => 'This training is required for you and no completion is on record.',
                },
                completion: $current
                    ? 'Nothing — this is done.'
                    : ($training->isOnline()
                        ? 'Complete the training from its training page.'
                        : 'Sign up for a session and attend it, so your completion is recorded.'),
                dueAt: $shiftDeadlines[$trainingId] ?? null,
                actionSurface: 'department.training-detail',
                actionLabel: $training->isOnline()
                    ? 'Open the training page'
                    : 'Open session signup',
                actionParams: array_filter([
                    'department_id' => $this->departmentIdFor($training),
                    'training_id' => $trainingId,
                ], fn (?string $value): bool => $value !== null),
            );
        }

        return $items;
    }

    /**
     * Every training required for this viewer, keyed by id.
     *
     * @return array<string, Training>
     */
    private function requiredTrainings(EventHorizonViewer $viewer, Event $event): array
    {
        $required = [];

        /*
         * Membership-required: scoped to a team the member is on, or to a
         * department they belong to with no narrower team scope. A training
         * bound to a different event is not required for this one.
         */
        $membershipRequired = Training::query()
            ->active()
            ->where('organization_id', $event->organization_id)
            ->where(function (Builder $query) use ($event): void {
                $query->whereNull('event_id')->orWhere('event_id', $event->id);
            })
            ->where(function (Builder $query) use ($viewer): void {
                $query
                    ->whereIn('team_id', $viewer->teamIds)
                    ->orWhere(fn (Builder $scoped) => $scoped
                        ->whereNull('team_id')
                        ->whereIn('department_id', $viewer->departmentIds()));
            })
            ->get();

        foreach ($membershipRequired as $training) {
            $required[(string) $training->getKey()] = $training;
        }

        // Shift-required (SHIFT-005), for the shifts this member holds.
        $heldShifts = $this->heldShifts($viewer, $event);

        foreach ($heldShifts as $shift) {
            foreach ($shift->requiredTrainings as $training) {
                if ($training->isArchived()) {
                    continue;
                }

                $required[(string) $training->getKey()] = $training;
            }
        }

        return $required;
    }

    /**
     * @return Collection<int, Shift>
     */
    private function heldShifts(EventHorizonViewer $viewer, Event $event)
    {
        if ($viewer->staffIds === []) {
            return Shift::query()->whereRaw('1 = 0')->get();
        }

        $shiftIds = ShiftAssignment::query()
            ->active()
            ->whereIn('staff_id', $viewer->staffIds)
            ->whereHas('shift', fn (Builder $query) => $query->where('event_id', $event->id))
            ->pluck('shift_id')
            ->all();

        return Shift::query()
            ->whereIn('id', $shiftIds)
            ->active()
            ->with('requiredTrainings')
            ->get();
    }

    /**
     * The earliest start of a held shift requiring each training — the moment
     * TRAIN-008 makes the completion matter.
     *
     * @param  list<string>  $trainingIds
     * @return array<string, Carbon>
     */
    private function heldShiftDeadlines(EventHorizonViewer $viewer, Event $event, array $trainingIds, Carbon $now): array
    {
        $deadlines = [];

        foreach ($this->heldShifts($viewer, $event) as $shift) {
            if ($shift->starts_at === null || $shift->starts_at->lessThan($now)) {
                continue;
            }

            foreach ($shift->requiredTrainings as $training) {
                $id = (string) $training->getKey();

                if (! in_array($id, $trainingIds, true)) {
                    continue;
                }

                if (! isset($deadlines[$id]) || $shift->starts_at->lessThan($deadlines[$id])) {
                    $deadlines[$id] = $shift->starts_at;
                }
            }
        }

        return $deadlines;
    }

    /**
     * The department whose training pages carry this training: its own, or its
     * team's for a team-scoped one.
     */
    private function departmentIdFor(Training $training): ?string
    {
        if ($training->department_id !== null) {
            return (string) $training->department_id;
        }

        if ($training->team_id !== null) {
            return (string) Team::query()->find($training->team_id)?->department_id;
        }

        return null;
    }
}
