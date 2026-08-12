<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardAttention;
use App\Domain\Dashboard\DashboardWidget;
use App\Domain\Modules\ModuleKey;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\EquipmentCheckout;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Equipment\EquipmentCheckoutPresentation;
use Illuminate\Support\Carbon;

/**
 * UI contract 13.6, the kiosk widgets (M18.28).
 *
 * The group is opened by the trusted workstation and each widget inside it is
 * gated on what the person signed in at that workstation may do — widget spec 9,
 * "Kiosk widgets must distinguish trusted workstation state from individual user
 * authority". A pinned Kiosk with nobody's attendance authority behind it offers
 * no check-in widget, because tapping it would reach a command the node refuses.
 *
 * `kiosk.node_status` and `kiosk.switch_user` are not compiled here at all. They
 * are catalogued as device-evaluated: whether the local node is reachable and
 * who is standing at this workstation are facts about the machine, and a node
 * answering the first of them could only ever say yes.
 *
 * Kiosk widgets are also deliberately coarser than their department
 * counterparts. Widget spec 9 asks them to hide admin complexity and stay
 * touch-sized, so what a desk reads as a five-row list a Kiosk reads as a count
 * and one large action.
 */
final class KioskDashboardWidgets extends DashboardWidgetCompiler
{
    /**
     * @return list<DashboardWidget>
     */
    public function compile(Event $event, DashboardAudience $audience, Carbon $now): array
    {
        $workstation = $audience->workstation;
        $department = $audience->department;
        $authority = $audience->departmentAuthority;

        if ($workstation === null) {
            return [];
        }

        /*
         * The contract grants both to "shift/department lead". Each is gated
         * here on the authority the command behind it actually takes, which is
         * the narrower and safer reading: check-in answers to TEAM-015's
         * attendance manager (logistics, department leads, and shift leads), and
         * a return answers to `department.equipment.manage`. A widget nobody
         * could act on is absent rather than refused on tap (CLIENT-005).
         */
        /*
         * The module check sits beside the capability check rather than after
         * it, because `kiosk.current_tasks` is compiled from these two answers
         * and is itself core (MOD-019, M19.18). Its own widget is dropped later
         * for a module the organization does not run; the sum below would still
         * have counted it, and a Kiosk reading "3 tasks" over a list of one is
         * worse than either of the honest answers.
         */
        $checkIns = $department !== null
            && $authority?->canManageAttendance === true
            && $this->runs(ModuleKey::Scheduling)
            ? $this->awaitingCheckIn($event, $department, $now)
            : null;

        $returns = $department !== null
            && $authority?->canManageEquipment === true
            && $this->runs(ModuleKey::Equipment)
            ? $this->outstandingReturns($event, $department, $now)
            : null;

        $widgets = [$this->currentTasks($checkIns, $returns)];

        if ($checkIns !== null) {
            $widgets[] = $this->staffCheckIn($checkIns);
        }

        if ($returns !== null) {
            $widgets[] = $this->equipmentReturns($returns);
        }

        return $widgets;
    }

    /**
     * What this workstation can be used for right now (UI contract 13.6,
     * `kiosk.current_tasks`).
     *
     * The sum of the two operational widgets below rather than a list of its
     * own. A Kiosk's first screen answers "is there anything for me to do here",
     * and a third source of tasks that disagreed with the two beneath it would
     * make that answer worse.
     *
     * @param  array{count: int, urgent: bool}|null  $checkIns
     * @param  array{count: int, urgent: bool}|null  $returns
     */
    private function currentTasks(?array $checkIns, ?array $returns): DashboardWidget
    {
        $items = [];
        $total = 0;

        if ($checkIns !== null && $checkIns['count'] > 0) {
            $total += $checkIns['count'];
            $items[] = $this->item('Staff to check in', null, (string) $checkIns['count']);
        }

        if ($returns !== null && $returns['count'] > 0) {
            $total += $returns['count'];
            $items[] = $this->item('Equipment to take back', null, (string) $returns['count']);
        }

        if ($items === []) {
            return $this->quiet('kiosk.current_tasks');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('kiosk.current_tasks'),
            attention: ($checkIns['urgent'] ?? false) || ($returns['urgent'] ?? false)
                ? DashboardAttention::Warning
                : DashboardAttention::Attention,
            summary: $this->plural($total, 'task').' at this workstation.',
            items: $items,
            metric: ['value' => $total, 'label' => 'tasks'],
        );
    }

    /**
     * @param  array{count: int, urgent: bool}  $checkIns
     */
    private function staffCheckIn(array $checkIns): DashboardWidget
    {
        if ($checkIns['count'] === 0) {
            return $this->quiet('kiosk.staff_checkin');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('kiosk.staff_checkin'),
            attention: $checkIns['urgent'] ? DashboardAttention::Warning : DashboardAttention::Attention,
            summary: $this->plural($checkIns['count'], 'staff member').' on a running shift are not checked in.',
            metric: ['value' => $checkIns['count'], 'label' => 'awaiting check-in'],
        );
    }

    /**
     * @param  array{count: int, urgent: bool}  $returns
     */
    private function equipmentReturns(array $returns): DashboardWidget
    {
        if ($returns['count'] === 0) {
            return $this->quiet('kiosk.equipment_returns');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('kiosk.equipment_returns'),
            attention: $returns['urgent'] ? DashboardAttention::Warning : DashboardAttention::Routine,
            summary: $this->plural($returns['count'], 'item').' still out from this department'
                .($returns['urgent'] ? ', some overdue' : '').'.',
            metric: ['value' => $returns['count'], 'label' => 'items out'],
        );
    }

    /**
     * People assigned to a shift that is running who have not arrived.
     *
     * Urgent once anybody has been marked a no-show, which is the point at which
     * the desk has stopped waiting and started recording.
     *
     * @return array{count: int, urgent: bool}
     */
    private function awaitingCheckIn(Event $event, Department $department, Carbon $now): array
    {
        $shiftIds = Shift::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($shiftIds === []) {
            return ['count' => 0, 'urgent' => false];
        }

        $assigned = ShiftAssignment::query()
            ->active()
            ->whereIn('shift_id', $shiftIds)
            ->count();

        $arrived = 0;
        $noShows = 0;

        foreach (AttendanceRecord::query()->whereIn('shift_id', $shiftIds)->get() as $record) {
            if ($record->checked_in_at !== null) {
                $arrived++;
            }

            if ($record->current_state === AttendanceRecord::STATE_NO_SHOW) {
                $noShows++;
            }
        }

        return [
            'count' => max(0, $assigned - $arrived - $noShows),
            'urgent' => $noShows > 0,
        ];
    }

    /**
     * @return array{count: int, urgent: bool}
     */
    private function outstandingReturns(Event $event, Department $department, Carbon $now): array
    {
        $open = EquipmentCheckout::query()
            ->with(['equipmentItem', 'shift'])
            ->outstandingForDepartment($event, $department)
            ->get();

        $overdue = $open->filter(
            fn (EquipmentCheckout $checkout): bool => EquipmentCheckoutPresentation::describe(
                $checkout,
                $checkout->shift,
                $event,
                $now,
            )['overdue'] === true,
        )->count();

        return ['count' => $open->count(), 'urgent' => $overdue > 0];
    }
}
