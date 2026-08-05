<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardAttention;
use App\Domain\Dashboard\DashboardWidget;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\EquipmentCheckout;
use App\Models\Event;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftTrainingRequirement;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Services\Equipment\EquipmentCheckoutPresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * UI contract 13.2, the department lead widgets (M18.28).
 *
 * The group is opened by having standing in the department; each widget inside
 * it is gated on the standing the contract names for it. Check-in status and
 * equipment returns belong to `department_logistics` and the rest to the
 * department lead, which is not a formality: somebody running the desk and
 * somebody running the department read different halves of this table, and a
 * dashboard that showed both halves to either would be granting authority the
 * commands behind them would refuse.
 *
 * Coverage and readiness are deliberately two widgets rather than one. Coverage
 * is "this shift is short of people". Readiness is "this shift is not in a state
 * where anybody can fix that" — no eligible team to sign up from, no capacity
 * target to be short of, or a roster already frozen while short. A lead reading
 * a single number could not tell which of the two they were looking at, and the
 * two need different actions.
 */
final class DepartmentLeadDashboardWidgets extends DashboardWidgetCompiler
{
    /** Inside this many hours, a short shift is a warning rather than a note. */
    private const IMMINENT_HOURS = 24;

    /**
     * @return list<DashboardWidget>
     */
    public function compile(Event $event, DashboardAudience $audience, Carbon $now): array
    {
        $department = $audience->department;
        $authority = $audience->departmentAuthority;

        if ($department === null || $authority === null) {
            return [];
        }

        $widgets = [];

        if ($authority->isDepartmentLead) {
            $shifts = $this->departmentShifts($event, $department);
            $assigned = $this->assignmentCounts($shifts);

            $widgets[] = $this->coverageIssues($event, $shifts, $assigned, $now);
            $widgets[] = $this->shiftReadiness($event, $shifts, $assigned, $now);
            $widgets[] = $this->trainingReadiness($event, $department, $now);
            $widgets[] = $this->policyReadiness($department);
        }

        /*
         * The contract grants these two to `department_logistics` and their
         * action opens the Logistics Window, which admits exactly that role
         * (UI contract 12.5). Gating on `canManageAttendance` instead would put
         * them on a department lead's dashboard pointing at a surface the lead
         * cannot open — M18.13 widened attendance management to leads and shift
         * leads, and this is one of the places that widening must not reach.
         */
        if ($audience->isDepartmentLogistics) {
            $widgets[] = $this->checkInStatus($event, $department, $now);
            $widgets[] = $this->equipmentReturns($event, $department, $now);
        }

        return $widgets;
    }

    /**
     * Shifts short of the people they asked for (UI contract 13.2,
     * `dept.coverage_issues`).
     *
     * Only shifts that have not finished. A shift that ran short yesterday is
     * history a lead cannot act on, and reporting it as a coverage issue would
     * keep the widget lit for the rest of the event.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  array<string, int>  $assigned
     */
    private function coverageIssues(
        Event $event,
        Collection $shifts,
        array $assigned,
        Carbon $now,
    ): DashboardWidget {
        $short = $shifts
            ->filter(fn (Shift $shift): bool => ! $this->hasFinished($shift, $now)
                && $shift->capacity !== null
                && ($assigned[(string) $shift->id] ?? 0) < $shift->capacity)
            ->sortBy(fn (Shift $shift): string => (string) $shift->starts_at?->toIso8601String())
            ->values();

        if ($short->isEmpty()) {
            return $this->quiet('dept.coverage_issues');
        }

        $imminent = $short->contains(fn (Shift $shift): bool => $shift->starts_at !== null
            && $shift->starts_at->lessThanOrEqualTo($now->copy()->addHours(self::IMMINENT_HOURS)));

        $shortfall = $short->sum(
            fn (Shift $shift): int => (int) $shift->capacity - ($assigned[(string) $shift->id] ?? 0),
        );

        return DashboardWidget::reporting(
            definition: $this->definition('dept.coverage_issues'),
            attention: $imminent ? DashboardAttention::Warning : DashboardAttention::Attention,
            summary: $this->plural($short->count(), 'shift').' short of '.$this->plural($shortfall, 'staff member').'.',
            items: $this->capped($short
                ->map(fn (Shift $shift): array => $this->item(
                    label: (string) $shift->title,
                    detail: $this->eventTime($event, $shift->starts_at),
                    status: (((int) $shift->capacity) - ($assigned[(string) $shift->id] ?? 0)).' short of '.$shift->capacity,
                ))
                ->values()
                ->all()),
            metric: ['value' => $shortfall, 'label' => 'unfilled places'],
        );
    }

    /**
     * Shifts nobody can fill (UI contract 13.2, `dept.shift_readiness`).
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  array<string, int>  $assigned
     */
    private function shiftReadiness(
        Event $event,
        Collection $shifts,
        array $assigned,
        Carbon $now,
    ): DashboardWidget {
        $items = [];
        $attention = DashboardAttention::Routine;

        foreach ($shifts->sortBy(fn (Shift $shift): string => (string) $shift->starts_at?->toIso8601String()) as $shift) {
            if ($this->hasFinished($shift, $now)) {
                continue;
            }

            $when = $this->eventTime($event, $shift->starts_at);
            $on = $assigned[(string) $shift->id] ?? 0;

            if ($shift->eligible_team_id === null) {
                $items[] = $this->item((string) $shift->title, $when, 'No eligible team');
                $attention = $this->raise($attention, DashboardAttention::Warning);

                continue;
            }

            if ($shift->capacity === null) {
                $items[] = $this->item((string) $shift->title, $when, 'No capacity target');
                $attention = $this->raise($attention, DashboardAttention::Attention);

                continue;
            }

            /*
             * A roster that can no longer change while it is short. The lock is
             * the point of no return for signup (SHIFT-017), so this is the one
             * readiness state that will not resolve itself and the only one that
             * needs the lead rather than the shift board.
             */
            if ($on < $shift->capacity && $this->rosterIsFrozen($shift, $now)) {
                $items[] = $this->item(
                    (string) $shift->title,
                    $when,
                    'Signup closed with '.($shift->capacity - $on).' unfilled',
                );
                $attention = $this->raise($attention, DashboardAttention::Warning);
            }
        }

        if ($items === []) {
            return $this->quiet('dept.shift_readiness');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('dept.shift_readiness'),
            attention: $attention,
            summary: $this->plural(count($items), 'shift').' cannot be filled as it stands.',
            items: $this->capped($items),
            metric: ['value' => count($items), 'label' => 'shifts'],
        );
    }

    /**
     * Who has not arrived for a shift that is running (UI contract 13.2,
     * `dept.checkin_status`).
     *
     * Counts rather than names. This is a lead's glance at whether the desk is
     * on top of arrivals; the Logistics Window is where somebody is found by
     * name, and it is where the widget's action goes.
     */
    private function checkInStatus(Event $event, Department $department, Carbon $now): DashboardWidget
    {
        $running = $this->runningShifts($event, $department, $now);

        if ($running->isEmpty()) {
            return $this->quiet('dept.checkin_status');
        }

        $shiftIds = $running->map(fn (Shift $shift): string => (string) $shift->id)->all();
        $attendance = $this->attendanceByShift($shiftIds);
        $assigned = $this->assignmentCounts($running);

        $items = [];
        $awaiting = 0;
        $noShows = 0;

        foreach ($running as $shift) {
            $id = (string) $shift->id;
            $arrived = $attendance[$id]['arrived'] ?? 0;
            $noShow = $attendance[$id]['no_show'] ?? 0;
            $on = $assigned[$id] ?? 0;
            $outstanding = max(0, $on - $arrived - $noShow);

            if ($outstanding === 0 && $noShow === 0) {
                continue;
            }

            $awaiting += $outstanding;
            $noShows += $noShow;

            $items[] = $this->item(
                label: (string) $shift->title,
                detail: $this->eventTime($event, $shift->starts_at),
                status: $outstanding.' of '.$on.' not checked in'.($noShow > 0 ? ', '.$noShow.' no-show' : ''),
            );
        }

        if ($items === []) {
            return $this->quiet('dept.checkin_status');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('dept.checkin_status'),
            attention: $noShows > 0 ? DashboardAttention::Warning : DashboardAttention::Attention,
            summary: $this->plural($awaiting, 'staff member').' not checked in on a running shift.',
            items: $this->capped($items),
            metric: ['value' => $awaiting, 'label' => 'awaiting check-in'],
        );
    }

    /**
     * Required trainings the department's rostered staff have not completed
     * (UI contract 13.2, `dept.training_readiness`; TRAIN-008 through
     * TRAIN-010).
     *
     * Read from the shifts rather than from the people: a training matters here
     * because a shift requires it, and somebody who holds no shift requiring it
     * is not unready. An expired completion counts as missing, which is the
     * whole reason completions carry an expiry.
     */
    private function trainingReadiness(Event $event, Department $department, Carbon $now): DashboardWidget
    {
        $upcoming = Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($upcoming === []) {
            return $this->quiet('dept.training_readiness');
        }

        $requirements = ShiftTrainingRequirement::query()
            ->whereIn('shift_id', $upcoming)
            ->get()
            ->groupBy(fn (ShiftTrainingRequirement $requirement): string => (string) $requirement->training_id);

        if ($requirements->isEmpty()) {
            return $this->quiet('dept.training_readiness');
        }

        $rosters = ShiftAssignment::query()
            ->active()
            ->whereIn('shift_id', $upcoming)
            ->get()
            ->groupBy(fn (ShiftAssignment $assignment): string => (string) $assignment->shift_id);

        $items = [];
        $missing = 0;

        foreach ($requirements as $trainingId => $rows) {
            $staffIds = collect($rows)
                ->flatMap(fn (ShiftTrainingRequirement $requirement): array => $rosters
                    ->get((string) $requirement->shift_id, collect())
                    ->map(fn (ShiftAssignment $assignment): string => (string) $assignment->staff_id)
                    ->all())
                ->unique()
                ->values();

            if ($staffIds->isEmpty()) {
                continue;
            }

            $completed = TrainingCompletion::query()
                ->where('training_id', $trainingId)
                ->whereIn('staff_id', $staffIds->all())
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->pluck('staff_id')
                ->map(fn ($id): string => (string) $id)
                ->unique();

            $short = $staffIds->reject(fn (string $id): bool => $completed->contains($id))->count();

            if ($short === 0) {
                continue;
            }

            $missing += $short;
            $items[] = $this->item(
                label: (string) Training::query()->find($trainingId)?->name,
                detail: 'Required by a shift in this department',
                status: $this->plural($short, 'staff member').' outstanding',
            );
        }

        if ($items === []) {
            return $this->quiet('dept.training_readiness');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('dept.training_readiness'),
            attention: DashboardAttention::Attention,
            summary: $this->plural($missing, 'rostered staff member').' missing a required training.',
            items: $this->capped($items),
            metric: ['value' => $missing, 'label' => 'outstanding'],
        );
    }

    /**
     * Department documents the department's members have been asked to
     * acknowledge and have not (UI contract 13.2, `dept.policy_readiness`).
     *
     * Scope is the department and nothing wider. An organization-wide
     * requirement is the organizer's readiness (13.4) and reporting it here
     * would put the same gap on two people's screens with neither owning it.
     */
    private function policyReadiness(Department $department): DashboardWidget
    {
        $requirements = DocumentAcknowledgmentRequirement::query()
            ->active()
            ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT)
            ->where('scope_id', $department->id)
            ->get();

        if ($requirements->isEmpty()) {
            return $this->quiet('dept.policy_readiness');
        }

        $memberStaffIds = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->pluck('staff_id')
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $items = [];
        $outstanding = 0;

        foreach ($requirements as $requirement) {
            $document = $this->documentFor(
                (string) $requirement->document_type,
                (string) $requirement->document_id,
            );

            if ($document === null) {
                continue;
            }

            if (! $document->isPublished()) {
                // A requirement nobody can satisfy, which is a readiness gap of
                // its own and the one a lead can actually close.
                $items[] = $this->item((string) $document->title, 'Required by this department', 'Document not published');

                continue;
            }

            if ($memberStaffIds->isEmpty()) {
                continue;
            }

            $acknowledged = DocumentAcknowledgment::query()
                ->where('document_type', $requirement->document_type)
                ->where('document_id', $requirement->document_id)
                ->where('scope_type', $requirement->scope_type)
                ->where('scope_id', $requirement->scope_id)
                ->whereIn('staff_id', $memberStaffIds->all())
                ->pluck('staff_id')
                ->map(fn ($id): string => (string) $id)
                ->unique();

            $short = $memberStaffIds->reject(fn (string $id): bool => $acknowledged->contains($id))->count();

            if ($short === 0) {
                continue;
            }

            $outstanding += $short;
            $items[] = $this->item(
                label: (string) $document->title,
                detail: 'Required by this department',
                status: $this->plural($short, 'member').' have not acknowledged',
            );
        }

        if ($items === []) {
            return $this->quiet('dept.policy_readiness');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('dept.policy_readiness'),
            attention: DashboardAttention::Attention,
            summary: $this->plural(count($items), 'department document').' still outstanding.',
            items: $this->capped($items),
            metric: ['value' => $outstanding, 'label' => 'acknowledgments outstanding'],
        );
    }

    /**
     * Equipment still in somebody's hands (UI contract 13.2,
     * `dept.equipment_returns`; EQUIP-005, EQUIP-009).
     *
     * Overdue is derived here rather than stored, exactly as the Logistics
     * Window derives it, because it becomes true as a clock passes a time and a
     * stored column would say "checked out" for hours after it stopped being so.
     */
    private function equipmentReturns(Event $event, Department $department, Carbon $now): DashboardWidget
    {
        $outstanding = EquipmentCheckout::query()
            ->with(['equipmentItem', 'shift', 'staff'])
            ->outstandingForDepartment($event, $department)
            ->get();

        if ($outstanding->isEmpty()) {
            return $this->quiet('dept.equipment_returns');
        }

        $items = [];
        $overdue = 0;

        foreach ($outstanding as $checkout) {
            $derived = EquipmentCheckoutPresentation::describe($checkout, $checkout->shift, $event, $now);

            if ($derived['overdue'] === true) {
                $overdue++;
            }

            $items[] = $this->item(
                label: (string) ($checkout->equipmentItem?->name ?? 'Equipment'),
                detail: $checkout->staff?->displayName(),
                status: (string) $derived['state_label'],
            );
        }

        // Overdue first: a lead glancing at five rows should be reading the ones
        // that are late rather than the ones that happen to sort first.
        usort($items, fn (array $left, array $right): int => ($right['status'] === 'Overdue' ? 1 : 0)
            <=> ($left['status'] === 'Overdue' ? 1 : 0));

        return DashboardWidget::reporting(
            definition: $this->definition('dept.equipment_returns'),
            attention: $overdue > 0 ? DashboardAttention::Warning : DashboardAttention::Routine,
            summary: $overdue > 0
                ? $this->plural($overdue, 'item').' overdue of '.$this->plural($outstanding->count(), 'item').' still out.'
                : $this->plural($outstanding->count(), 'item').' still out, none overdue.',
            items: $this->capped($items),
            metric: ['value' => $outstanding->count(), 'label' => 'items out'],
        );
    }

    /**
     * @return Collection<int, Shift>
     */
    private function departmentShifts(Event $event, Department $department): Collection
    {
        return Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->get();
    }

    /**
     * @return Collection<int, Shift>
     */
    private function runningShifts(Event $event, Department $department, Carbon $now): Collection
    {
        return Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Live assignments per shift.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return array<string, int>
     */
    private function assignmentCounts(Collection $shifts): array
    {
        $ids = $shifts->map(fn (Shift $shift): string => (string) $shift->id)->all();

        if ($ids === []) {
            return [];
        }

        return ShiftAssignment::query()
            ->active()
            ->whereIn('shift_id', $ids)
            ->get()
            ->groupBy(fn (ShiftAssignment $assignment): string => (string) $assignment->shift_id)
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    /**
     * Arrivals and no-shows per shift.
     *
     * @param  list<string>  $shiftIds
     * @return array<string, array{arrived: int, no_show: int}>
     */
    private function attendanceByShift(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $counts = [];

        foreach (AttendanceRecord::query()->whereIn('shift_id', $shiftIds)->get() as $record) {
            $id = (string) $record->shift_id;
            $counts[$id] ??= ['arrived' => 0, 'no_show' => 0];

            if ($record->checked_in_at !== null) {
                $counts[$id]['arrived']++;
            }

            if ($record->current_state === AttendanceRecord::STATE_NO_SHOW) {
                $counts[$id]['no_show']++;
            }
        }

        return $counts;
    }

    private function hasFinished(Shift $shift, Carbon $now): bool
    {
        return $shift->ends_at !== null && $shift->ends_at->lessThan($now);
    }

    /**
     * Whether the roster can still change (SHIFT-009, SHIFT-017).
     *
     * The schedule lock and the signup close are separate gates and either one
     * closing is enough to freeze what a shift board can do about a short shift.
     */
    private function rosterIsFrozen(Shift $shift, Carbon $now): bool
    {
        if ($shift->schedule_lock_at !== null && $shift->schedule_lock_at->lessThanOrEqualTo($now)) {
            return true;
        }

        return $shift->signup_closes_at !== null && $shift->signup_closes_at->lessThanOrEqualTo($now);
    }

    private function documentFor(string $type, string $id): PolicyDocument|ProcedureDocument|null
    {
        return $type === 'procedure'
            ? ProcedureDocument::query()->find($id)
            : PolicyDocument::query()->find($id);
    }

    private function raise(DashboardAttention $current, DashboardAttention $candidate): DashboardAttention
    {
        return $candidate->rank() < $current->rank() ? $candidate : $current;
    }
}
