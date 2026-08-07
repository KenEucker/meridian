<?php

declare(strict_types=1);

namespace App\Services\DepartmentOps;

use App\Models\AttendanceRecord;
use App\Models\HoursWorked;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The identity-free plan-versus-actual arithmetic behind the Planning Table
 * (SLB-019, SLB-020).
 *
 * One row per shift, and every field on it a count, an hour figure, or a label
 * derived from those. No staff id, no name, no assignment id, and no signup list
 * reaches a row from here — the row *is* the shift, and the numbers on it are
 * numbers.
 *
 * It is a service rather than a method on the read that first needed it because
 * the offline read set composes the same rows for a device to hold (M18.47;
 * technical spec 9.3). A planner comparing an offline table against an online
 * one is comparing the same arithmetic, and the alternative — two
 * implementations of "planned hours" — is a variance figure that changes when
 * the connection does.
 */
final class PlanVersusActual
{
    /**
     * @param  Collection<int, Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $shifts, Carbon $now): array
    {
        if ($shifts->isEmpty()) {
            return [];
        }

        $shiftIds = $shifts->modelKeys();
        $assignments = $this->assignmentCounts($shiftIds);
        $attendance = $this->attendanceCounts($shiftIds);
        $minutes = $this->actualMinutes($shiftIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = $shifts
            ->map(fn (Shift $shift): array => $this->row(
                $shift,
                $now,
                $assignments[(string) $shift->getKey()] ?? ['total' => 0, 'unscheduled' => 0],
                $attendance[(string) $shift->getKey()] ?? ['checked_in' => 0, 'no_show' => 0],
                $minutes[(string) $shift->getKey()] ?? 0,
            ))
            ->values()
            ->all();

        return $rows;
    }

    /**
     * Planned hours are what the shift asked for: its capacity across its own
     * window, or — where it sets no capacity target — the people actually on it,
     * because a shift with no target cannot be under one.
     *
     * @param  array{total: int, unscheduled: int}  $assignments
     * @param  array{checked_in: int, no_show: int}  $attendance
     * @return array<string, mixed>
     */
    private function row(
        Shift $shift,
        Carbon $now,
        array $assignments,
        array $attendance,
        int $actualMinutes,
    ): array {
        $lifecycle = ShiftLifecycle::of($shift, $now);
        $durationHours = $shift->starts_at !== null && $shift->ends_at !== null
            ? $shift->starts_at->diffInMinutes($shift->ends_at) / 60
            : 0.0;
        $plannedHours = round(($shift->capacity ?? $assignments['total']) * $durationHours, 1);
        $actualHours = round($actualMinutes / 60, 1);

        return [
            'shift_id' => (string) $shift->getKey(),
            'title' => $shift->title,
            'team_id' => (string) $shift->eligible_team_id,
            'team_label' => $shift->eligibleTeam?->name ?? $shift->team_name_snapshot,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'lifecycle' => $lifecycle,
            'capacity' => $shift->capacity,
            'signed_up_or_assigned_count' => $assignments['total'],
            'checked_in_count' => $attendance['checked_in'],
            'no_show_count' => $attendance['no_show'],
            'unscheduled_count' => $assignments['unscheduled'],
            'planned_hours' => $plannedHours,
            'actual_hours' => $actualHours,
            'variance_hours' => round($actualHours - $plannedHours, 1),
            'status_label' => $this->statusLabel(
                $lifecycle,
                $shift->capacity,
                $assignments['total'],
                $plannedHours,
                $actualHours,
            ),
        ];
    }

    private function statusLabel(
        string $lifecycle,
        ?int $capacity,
        int $assigned,
        float $plannedHours,
        float $actualHours,
    ): string {
        return match (true) {
            $lifecycle === ShiftLifecycle::CANCELLED => 'Cancelled',
            $lifecycle === ShiftLifecycle::UPCOMING && $capacity !== null && $assigned < $capacity => 'Under target',
            $lifecycle === ShiftLifecycle::UPCOMING => 'Upcoming',
            $lifecycle === ShiftLifecycle::COMPLETED && $actualHours > $plannedHours => 'Completed over plan',
            $lifecycle === ShiftLifecycle::COMPLETED => 'Completed under plan',
            $capacity !== null && $assigned < $capacity => 'Under target',
            default => 'On plan',
        };
    }

    /**
     * @param  list<mixed>  $shiftIds
     * @return array<string, array{total: int, unscheduled: int}>
     */
    private function assignmentCounts(array $shiftIds): array
    {
        $counts = [];

        foreach (
            ShiftAssignment::query()
                ->with('shift:id,starts_at')
                ->whereIn('shift_id', $shiftIds)
                ->whereNull('removed_at')
                ->get() as $assignment
        ) {
            $shiftId = (string) $assignment->shift_id;
            $counts[$shiftId] ??= ['total' => 0, 'unscheduled' => 0];
            $counts[$shiftId]['total']++;

            $startsAt = $assignment->shift?->starts_at;

            // An assignment created after the shift started is the unscheduled
            // addition SLB-008 allows; Alpha 1 records no separate exception for
            // it (technical spec 20.5).
            if ($startsAt !== null
                && $assignment->created_at !== null
                && $assignment->created_at->greaterThanOrEqualTo($startsAt)) {
                $counts[$shiftId]['unscheduled']++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<mixed>  $shiftIds
     * @return array<string, array{checked_in: int, no_show: int}>
     */
    private function attendanceCounts(array $shiftIds): array
    {
        $counts = [];

        foreach (AttendanceRecord::query()->whereIn('shift_id', $shiftIds)->get() as $record) {
            $shiftId = (string) $record->shift_id;
            $counts[$shiftId] ??= ['checked_in' => 0, 'no_show' => 0];

            // Anyone who arrived, counted by their arrival rather than by the
            // state they are in now, so a completed shift still reports the
            // people who worked it.
            if ($record->checked_in_at !== null) {
                $counts[$shiftId]['checked_in']++;
            }

            if ($record->current_state === AttendanceRecord::STATE_NO_SHOW) {
                $counts[$shiftId]['no_show']++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<mixed>  $shiftIds
     * @return array<string, int>
     */
    private function actualMinutes(array $shiftIds): array
    {
        /** @var array<string, int> $minutes */
        $minutes = HoursWorked::query()
            ->whereIn('shift_id', $shiftIds)
            ->selectRaw('shift_id, sum(minutes_worked) as minutes')
            ->groupBy('shift_id')
            ->get()
            ->mapWithKeys(fn (mixed $row): array => [(string) $row->shift_id => (int) $row->minutes])
            ->all();

        return $minutes;
    }
}
