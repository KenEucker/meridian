<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardAttention;
use App\Domain\Dashboard\DashboardWidget;
use App\Models\AttendanceRecord;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\Deployment;
use App\Models\EquipmentCheckout;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Equipment\EquipmentCheckoutPresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * UI contract 13.3, the department operations widgets (M18.28).
 *
 * All four are scoped `shift/department/event`, and the shift is the one in
 * front of the person: the department shift running now, or the next one when
 * none is. That selection is the same one Department Overview makes when it
 * opens with no shift named, so a lead moving between the two is looking at the
 * same shift on both.
 *
 * With no shift to be about, these widgets are absent rather than quiet. A quiet
 * `shift.late_missing` says nobody is late; a department with no shift running
 * has nobody who could be, and saying "no late or missing staff" about a shift
 * that does not exist is reassurance about nothing.
 */
final class DepartmentOperationsDashboardWidgets extends DashboardWidgetCompiler
{
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

        $shift = $this->currentShift($event, $department, $now);

        if ($shift === null) {
            return [];
        }

        $assignments = ShiftAssignment::query()
            ->with('staff')
            ->active()
            ->where('shift_id', $shift->id)
            ->get();

        $attendance = $this->attendanceFor($shift);
        $widgets = [];

        if ($authority->canViewOperations()) {
            $widgets[] = $this->currentAssignments($event, $shift, $assignments, $attendance);
        }

        // Both are the contract's "department logistics" and both send the
        // reader to the Logistics Window, which admits that role alone.
        if ($audience->isDepartmentLogistics) {
            $widgets[] = $this->lateOrMissing($shift, $assignments, $attendance, $now);
        }

        if ($authority->canAssignDeployments) {
            $widgets[] = $this->deploymentNeeds($event, $department, $shift, $assignments, $attendance);
        }

        if ($audience->isDepartmentLogistics) {
            $widgets[] = $this->equipmentStatus($event, $shift, $now);
        }

        return $widgets;
    }

    /**
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @param  array<string, AttendanceRecord>  $attendance
     */
    private function currentAssignments(
        Event $event,
        Shift $shift,
        Collection $assignments,
        array $attendance,
    ): DashboardWidget {
        if ($assignments->isEmpty()) {
            return $this->quiet('shift.current_assignments');
        }

        $working = $assignments->filter(
            fn (ShiftAssignment $assignment): bool => ($attendance[(string) $assignment->staff_id] ?? null)?->current_state
                === AttendanceRecord::STATE_CHECKED_IN,
        )->count();

        return DashboardWidget::reporting(
            definition: $this->definition('shift.current_assignments'),
            attention: DashboardAttention::Routine,
            summary: $working.' of '.$this->plural($assignments->count(), 'assigned staff member')
                .' checked in on '.$shift->title.' ('.$this->eventTime($event, $shift->starts_at).').',
            items: $this->capped($assignments
                ->map(fn (ShiftAssignment $assignment): array => $this->item(
                    label: (string) ($assignment->staff?->displayName() ?? 'Staff member'),
                    detail: $shift->eligibleTeam?->name ?? $shift->team_name_snapshot,
                    status: $this->attendanceLabel($attendance[(string) $assignment->staff_id] ?? null),
                ))
                ->values()
                ->all()),
            metric: ['value' => $working, 'label' => 'checked in'],
        );
    }

    /**
     * Who has not arrived and who was written off (UI contract 13.3,
     * `shift.late_missing`).
     *
     * Late only counts once the shift has started. Before that, everybody who
     * has not arrived is early rather than late, and a widget that said
     * otherwise would be lit for every shift on the schedule.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @param  array<string, AttendanceRecord>  $attendance
     */
    private function lateOrMissing(
        Shift $shift,
        Collection $assignments,
        array $attendance,
        Carbon $now,
    ): DashboardWidget {
        $started = $shift->starts_at !== null && $shift->starts_at->lessThanOrEqualTo($now);
        $items = [];
        $late = 0;
        $missing = 0;

        foreach ($assignments as $assignment) {
            $record = $attendance[(string) $assignment->staff_id] ?? null;
            $name = (string) ($assignment->staff?->displayName() ?? 'Staff member');

            if ($record?->current_state === AttendanceRecord::STATE_NO_SHOW) {
                $missing++;
                $items[] = $this->item($name, null, 'No-show');

                continue;
            }

            if ($started && $record?->checked_in_at === null) {
                $late++;
                $items[] = $this->item($name, null, 'Not checked in');
            }
        }

        if ($items === []) {
            return $this->quiet('shift.late_missing');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('shift.late_missing'),
            attention: $missing > 0 ? DashboardAttention::Warning : DashboardAttention::Attention,
            summary: $this->plural($late, 'staff member').' not checked in'
                .($missing > 0 ? ' and '.$this->plural($missing, 'no-show').' recorded' : '').'.',
            items: $this->capped($items),
            metric: ['value' => $late + $missing, 'label' => 'unaccounted for'],
        );
    }

    /**
     * Working staff with nowhere recorded (UI contract 13.3,
     * `shift.deployment_needs`; SLB-009, SLB-010).
     *
     * Only checked-in staff. Somebody who has not arrived does not need a
     * deployment yet, and counting them would turn a shift's whole roster into
     * a need the moment it was published.
     *
     * A department with no deployment options at all is its own answer: there is
     * nothing to assign, and the Operations Center is where that is fixed.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @param  array<string, AttendanceRecord>  $attendance
     */
    private function deploymentNeeds(
        Event $event,
        Department $department,
        Shift $shift,
        Collection $assignments,
        array $attendance,
    ): DashboardWidget {
        $options = Deployment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->count();

        $working = $assignments->filter(
            fn (ShiftAssignment $assignment): bool => ($attendance[(string) $assignment->staff_id] ?? null)?->current_state
                === AttendanceRecord::STATE_CHECKED_IN,
        )->values();

        if ($options === 0) {
            return $working->isEmpty()
                ? $this->quiet('shift.deployment_needs')
                : DashboardWidget::reporting(
                    definition: $this->definition('shift.deployment_needs'),
                    attention: DashboardAttention::Attention,
                    summary: 'This department has no deployment options, so nobody working can be assigned one.',
                    metric: ['value' => $working->count(), 'label' => 'working'],
                );
        }

        $deployed = CurrentDeploymentAssignment::query()
            ->where('shift_id', $shift->id)
            ->pluck('staff_id')
            ->map(fn ($id): string => (string) $id)
            ->unique();

        $undeployed = $working->reject(
            fn (ShiftAssignment $assignment): bool => $deployed->contains((string) $assignment->staff_id),
        )->values();

        if ($undeployed->isEmpty()) {
            return $this->quiet('shift.deployment_needs');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('shift.deployment_needs'),
            attention: DashboardAttention::Attention,
            summary: $this->plural($undeployed->count(), 'staff member').' working with no deployment recorded.',
            items: $this->capped($undeployed
                ->map(fn (ShiftAssignment $assignment): array => $this->item(
                    label: (string) ($assignment->staff?->displayName() ?? 'Staff member'),
                    status: 'No deployment',
                ))
                ->values()
                ->all()),
            metric: ['value' => $undeployed->count(), 'label' => 'undeployed'],
        );
    }

    /**
     * What this shift still has out (UI contract 13.3,
     * `shift.equipment_status`).
     */
    private function equipmentStatus(Event $event, Shift $shift, Carbon $now): DashboardWidget
    {
        $open = EquipmentCheckout::query()
            ->with(['equipmentItem', 'staff'])
            ->where('event_id', $event->id)
            ->where('shift_id', $shift->id)
            ->whereNull('returned_at')
            ->get();

        if ($open->isEmpty()) {
            return $this->quiet('shift.equipment_status');
        }

        $overdue = 0;
        $items = [];

        foreach ($open as $checkout) {
            $derived = EquipmentCheckoutPresentation::describe($checkout, $shift, $event, $now);

            if ($derived['overdue'] === true) {
                $overdue++;
            }

            $items[] = $this->item(
                label: (string) ($checkout->equipmentItem?->name ?? 'Equipment'),
                detail: $checkout->staff?->displayName(),
                status: (string) $derived['state_label'],
            );
        }

        return DashboardWidget::reporting(
            definition: $this->definition('shift.equipment_status'),
            attention: $overdue > 0 ? DashboardAttention::Warning : DashboardAttention::Routine,
            summary: $this->plural($open->count(), 'item').' out on this shift'
                .($overdue > 0 ? ', '.$overdue.' overdue' : '').'.',
            items: $this->capped($items),
            metric: ['value' => $open->count(), 'label' => 'items out'],
        );
    }

    /**
     * The shift these widgets are about: the one running, or the next one.
     */
    private function currentShift(Event $event, Department $department, Carbon $now): ?Shift
    {
        $base = Shift::query()
            ->with('eligibleTeam')
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active();

        $running = (clone $base)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->orderBy('starts_at')
            ->first();

        return $running ?? (clone $base)
            ->where('starts_at', '>', $now)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @return array<string, AttendanceRecord>
     */
    private function attendanceFor(Shift $shift): array
    {
        $records = [];

        foreach (AttendanceRecord::query()->where('shift_id', $shift->id)->get() as $record) {
            $records[(string) $record->staff_id] = $record;
        }

        return $records;
    }

    private function attendanceLabel(?AttendanceRecord $record): string
    {
        return match ($record?->current_state) {
            AttendanceRecord::STATE_CHECKED_IN => 'Checked in',
            AttendanceRecord::STATE_CHECKED_OUT => 'Checked out',
            AttendanceRecord::STATE_NO_SHOW => 'No-show',
            AttendanceRecord::STATE_EXCUSED => 'Excused',
            AttendanceRecord::STATE_CORRECTED => 'Corrected',
            default => 'Not checked in',
        };
    }
}
