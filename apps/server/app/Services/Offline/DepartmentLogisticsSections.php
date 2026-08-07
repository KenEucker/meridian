<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\AttendanceRecord;
use App\Models\DepartmentMembership;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\EventDepartmentPresence;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\DepartmentOps\DeskHorizon;
use App\Services\Offline\Concerns\ShapesOfflineRows;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Department Logistics cache list of technical spec 9.3, composed for one
 * caller (SLB-003 through SLB-008, SLB-011, SLB-012, SLB-016 through SLB-018,
 * SLB-021).
 *
 * "Department-scoped searchable staff, equipment, and shift indexes for the
 * current event/department. Department on-site/off-site presence state. Current,
 * upcoming, and outgoing shift context for selected staff. Check-in/check-out/
 * no-show state for those department shifts. Department equipment state and open
 * checkouts they are permitted to manage. Future shift signups needed for the
 * selected staff workspace."
 *
 * This is the largest of the role-additive lists and the one whose size decides
 * whether the client store needs a query engine (M18.48), so two things about
 * its shape are deliberate.
 *
 * **It is the desk's horizon and not the event's.** The Logistics Window indexes
 * shifts within {@see DeskHorizon} of now, and the offline set carries exactly
 * that. A device holding every shift of a ten-day event would be storing rows no
 * surface renders, and a device holding fewer would show less with no signal than
 * with one. One shared constant is what keeps those two the same list.
 *
 * **It is rows and not the desk payload.** The Logistics read composes a
 * workspace per staff member with the derived answers — can this person go
 * off-site, may they be added to this shift, is this checkout overdue — and those
 * are answers about *now*, recomputed as the clock moves. A device with no signal
 * has its own clock and re-derives them from these rows; a set that carried the
 * answers would carry the moment they were true, and would be wrong within the
 * hour without ever saying so.
 *
 * Staff rows carry name and handle. The M8.2 exclusions hold across the whole
 * set (technical spec 9.5, data/API 7.3), and the profile picture the desk shows
 * online (M18.20) is not among what travels: it is storage metadata for an asset
 * the device does not hold, and a cached one outlives the profile change that
 * replaced it.
 */
final class DepartmentLogisticsSections implements OfflineReadSetContributor
{
    use ShapesOfflineRows;

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        $scopes = $scope->departmentScopesFor(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS);

        if ($scopes === []) {
            /*
             * A caller holding no Logistics role receives no Logistics section
             * at all — not empty ones. An empty section is a claim that the
             * department has no staff and no equipment, and this caller is not
             * entitled to make it.
             */
            return [];
        }

        $now = Carbon::now();

        $staffIndex = [];
        $presence = [];
        $shiftIndex = [];
        $assignments = [];
        $attendance = [];
        $equipment = [];
        $checkouts = [];
        $signups = [];

        foreach ($scopes as $desk) {
            $eventId = $desk['event_id'];
            $departmentId = $desk['department_id'];

            $staffIds = $this->departmentStaffIds($departmentId);
            $shifts = $this->deskShifts($eventId, $departmentId, $now);
            $shiftIds = array_column($shifts, 'id');

            $staffIndex = [...$staffIndex, ...$this->staffIndex($eventId, $departmentId, $staffIds)];
            $presence = [...$presence, ...$this->presence($eventId, $departmentId, $staffIds)];
            $shiftIndex = [...$shiftIndex, ...$shifts];
            $assignments = [...$assignments, ...$this->assignments($shiftIds)];
            $attendance = [...$attendance, ...$this->attendance($eventId, $departmentId, $shiftIds)];
            $equipment = [...$equipment, ...$this->equipment($departmentId)];
            $checkouts = [...$checkouts, ...$this->openCheckouts($eventId, $departmentId, $staffIds)];
            $signups = [...$signups, ...$this->futureSignups($eventId, $departmentId, $staffIds, $now)];
        }

        return [
            /*
             * Staff and presence are core (MOD-004). A department has people and
             * knows who is on site whether or not the organization runs
             * Scheduling, and a desk that lost its roster because a module was
             * switched off would be a desk that cannot find anybody.
             */
            OfflineReadSetSection::core('logistics_staff_index', $this->distinct($staffIndex)),
            OfflineReadSetSection::core('logistics_presence', $this->distinct($presence)),

            OfflineReadSetSection::owned('logistics_shift_index', ModuleKey::Scheduling, $this->distinct($shiftIndex)),
            OfflineReadSetSection::owned('logistics_shift_assignments', ModuleKey::Scheduling, $this->distinct($assignments)),
            OfflineReadSetSection::owned('logistics_attendance', ModuleKey::Scheduling, $this->distinct($attendance)),
            OfflineReadSetSection::owned('logistics_future_signups', ModuleKey::Scheduling, $this->distinct($signups)),

            OfflineReadSetSection::owned('logistics_equipment_index', ModuleKey::Equipment, $this->distinct($equipment)),
            OfflineReadSetSection::owned('logistics_equipment_checkouts', ModuleKey::Equipment, $this->distinct($checkouts)),
        ];
    }

    /**
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array
    {
        return [];
    }

    /**
     * The department's own staff, indexed for search (SLB-021).
     *
     * Active memberships in active standing, which is the same list the
     * Logistics read builds its workspaces from. The team label is carried
     * inline rather than left to be joined: the index is what a search box reads,
     * and a search that had to walk two other sections to show which crew
     * somebody is on would be the reason a client reached for a query engine.
     *
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    private function staffIndex(string $eventId, string $departmentId, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        $teamLabels = $this->teamLabels($departmentId, $staffIds);

        return $this->rows(
            Staff::query()->whereKey($staffIds)->orderBy('legal_name'),
            fn (Staff $member): array => [
                // Composite: one staff member indexed at one desk, so a caller
                // running two departments holds both without either overwriting
                // the other.
                'id' => $eventId.':'.$departmentId.':'.(string) $member->getKey(),
                'event_id' => $eventId,
                'department_id' => $departmentId,
                'staff_id' => (string) $member->getKey(),
                'legal_name' => $member->legal_name,
                'preferred_name' => $member->preferred_name,
                // Handle first is how a desk addresses somebody (VOL-010), and
                // the device derives the display name from these rather than
                // holding a third rendered copy of them.
                'handle' => $member->handle,
                'team_label' => $teamLabels[(string) $member->getKey()] ?? null,
                'archived_at' => $this->moment($member->archived_at),
            ],
        );
    }

    /**
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    private function presence(string $eventId, string $departmentId, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        return $this->rows(
            EventDepartmentPresence::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->whereIn('staff_id', $staffIds)
                ->orderBy('id'),
            fn (EventDepartmentPresence $presence): array => [
                'id' => (string) $presence->getKey(),
                'event_id' => (string) $presence->event_id,
                'department_id' => (string) $presence->department_id,
                'staff_id' => (string) $presence->staff_id,
                'current_state' => $presence->current_state,
                'marked_on_site_at' => $this->moment($presence->marked_on_site_at),
                'marked_off_site_at' => $this->moment($presence->marked_off_site_at),
            ],
        );
    }

    /**
     * The department's shifts inside the desk horizon (SLB-003).
     *
     * Cancelled shifts are left out, for the reason the Logistics read leaves
     * them out: nobody is expected at one and no attendance may be recorded
     * against one, so a device offering it offline could only mislead.
     *
     * @return list<array<string, mixed>>
     */
    private function deskShifts(string $eventId, string $departmentId, Carbon $now): array
    {
        return $this->rows(
            Shift::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->active()
                ->where('ends_at', '>=', DeskHorizon::endsAfter($now))
                ->where('starts_at', '<=', DeskHorizon::startsBefore($now))
                ->orderBy('starts_at')
                ->orderBy('id'),
            fn (Shift $shift): array => $this->shiftRow($shift),
        );
    }

    /**
     * @param  list<string>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function assignments(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return $this->rows(
            ShiftAssignment::query()
                ->whereIn('shift_id', $shiftIds)
                ->whereNull('removed_at')
                ->orderBy('id'),
            fn (ShiftAssignment $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'shift_id' => (string) $assignment->shift_id,
                'staff_id' => (string) $assignment->staff_id,
                'assignment_status' => $assignment->assignment_status,
                /*
                 * What makes an addition unscheduled (SLB-008): an assignment
                 * created after its shift started. The device compares this
                 * against the shift's own start rather than holding a flag,
                 * because the flag would have to be recomputed anyway.
                 */
                'created_at' => $this->moment($assignment->created_at),
            ],
        );
    }

    /**
     * Check-in, check-out, and no-show state for the desk's shifts (SLB-004
     * through SLB-007; technical spec 20.2).
     *
     * @param  list<string>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function attendance(string $eventId, string $departmentId, array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return $this->rows(
            AttendanceRecord::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->whereIn('shift_id', $shiftIds)
                ->orderBy('id'),
            fn (AttendanceRecord $record): array => [
                'id' => (string) $record->getKey(),
                'event_id' => (string) $record->event_id,
                'department_id' => (string) $record->department_id,
                'shift_id' => (string) $record->shift_id,
                'shift_assignment_id' => $record->shift_assignment_id === null
                    ? null
                    : (string) $record->shift_assignment_id,
                'staff_id' => (string) $record->staff_id,
                'current_state' => $record->current_state,
                'checked_in_at' => $this->moment($record->checked_in_at),
                'checked_out_at' => $this->moment($record->checked_out_at),
                'no_show_at' => $this->moment($record->no_show_at),
            ],
        );
    }

    /**
     * The department's equipment index (EQUIP-012, EQUIP-015; SLB-011).
     *
     * The department's own active inventory, which is the list the Logistics
     * Window searches and the set EQUIP-015 requires lookup to resolve against
     * on a device with no connectivity. Scope is the boundary and it is silent:
     * an asset tag belonging to another department is absent rather than
     * refused, because a distinct refusal for a real tag would disclose that the
     * tag exists.
     *
     * @return list<array<string, mixed>>
     */
    private function equipment(string $departmentId): array
    {
        return $this->rows(
            EquipmentItem::query()
                ->active()
                ->where('department_id', $departmentId)
                ->orderBy('name')
                ->orderBy('id'),
            fn (EquipmentItem $item): array => [
                'id' => (string) $item->getKey(),
                'organization_id' => (string) $item->organization_id,
                'event_id' => $item->event_id === null ? null : (string) $item->event_id,
                'department_id' => $item->department_id === null ? null : (string) $item->department_id,
                'name' => $item->name,
                'tracking' => $item->tracking,
                'asset_tag' => $item->asset_tag,
                'serial_number' => $item->serial_number,
                'quantity_total' => (int) $item->quantity_total,
                'status' => $item->status,
            ],
        );
    }

    /**
     * The checkouts still owed back (EQUIP-005, EQUIP-009, SLB-018).
     *
     * Open ones only. A returned checkout is history, and history is what an
     * online report is for; what a desk with no signal needs is what is still
     * out. Whether one is overdue is left to the device: it is a clock passing a
     * time, and a stored answer would read "checked out" for hours after it
     * stopped being true.
     *
     * The scope is `outstandingForDepartment`'s — the department's own items and
     * the unscoped repair tooling the same desk answers for (EQUIP-006) — which
     * is wider than the search index above. So the item's name and tag travel on
     * the checkout rather than being looked up in a section that may not carry
     * it: a device that could not name what somebody is holding could not ask
     * for it back.
     *
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    private function openCheckouts(string $eventId, string $departmentId, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        return $this->rows(
            EquipmentCheckout::query()
                ->with('equipmentItem')
                ->where('event_id', $eventId)
                ->whereNull('returned_at')
                ->whereIn('staff_id', $staffIds)
                ->whereHas('equipmentItem', fn (Builder $item) => $item->where(function (Builder $query) use ($departmentId): void {
                    $query->whereNull('department_id')->orWhere('department_id', $departmentId);
                }))
                ->orderBy('checked_out_at')
                ->orderBy('id'),
            fn (EquipmentCheckout $checkout): array => [
                'id' => (string) $checkout->getKey(),
                'equipment_item_id' => (string) $checkout->equipment_item_id,
                'event_id' => (string) $checkout->event_id,
                'staff_id' => (string) $checkout->staff_id,
                /*
                 * Null is not missing data: a radio signed out for the event is
                 * a different thing from one signed out with a shift, and only
                 * the second comes back when that shift ends (EQUIP-009).
                 */
                'shift_id' => $checkout->shift_id === null ? null : (string) $checkout->shift_id,
                'quantity' => (int) $checkout->quantity,
                'quantity_returned' => (int) $checkout->quantity_returned,
                'checked_out_at' => $this->moment($checkout->checked_out_at),
                'item_name' => $checkout->equipmentItem?->name,
                'item_tracking' => $checkout->equipmentItem?->tracking,
                'item_asset_tag' => $checkout->equipmentItem?->asset_tag,
                'item_status' => $checkout->equipmentItem?->status,
            ],
        );
    }

    /**
     * The signups a staff workspace shows beyond the desk's own horizon.
     *
     * Shift naming travels on the row rather than through a second shift
     * section: these are shifts the desk does not otherwise index, and one
     * section of a dozen fields is smaller than two sections and a join for a
     * list that only ever renders as "you are on this, then this".
     *
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    private function futureSignups(string $eventId, string $departmentId, array $staffIds, Carbon $now): array
    {
        if ($staffIds === []) {
            return [];
        }

        return $this->rows(
            ShiftAssignment::query()
                ->with('shift')
                ->whereIn('staff_id', $staffIds)
                ->whereNull('removed_at')
                ->whereHas('shift', fn (Builder $query) => $query
                    ->where('event_id', $eventId)
                    ->where('department_id', $departmentId)
                    ->active()
                    ->where('starts_at', '>', $now))
                ->orderBy('id'),
            fn (ShiftAssignment $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'shift_id' => (string) $assignment->shift_id,
                'staff_id' => (string) $assignment->staff_id,
                'assignment_status' => $assignment->assignment_status,
                'shift_title' => $assignment->shift?->title,
                'starts_at' => $this->moment($assignment->shift?->starts_at),
                'ends_at' => $this->moment($assignment->shift?->ends_at),
            ],
        );
    }

    /**
     * The staff the desk serves: active department memberships in active
     * standing, which is the list `DepartmentOperationsReadController` builds a
     * workspace for.
     *
     * @return list<string>
     */
    private function departmentStaffIds(string $departmentId): array
    {
        /** @var list<string> $ids */
        $ids = DepartmentMembership::query()
            ->active()
            ->where('department_id', $departmentId)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->pluck('staff_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Which crew each of these staff members is on, inside this department.
     *
     * Somebody on two teams is labelled with both, because the desk shows one
     * line and the operator should read the whole answer.
     *
     * @param  list<string>  $staffIds
     * @return array<string, string>
     */
    private function teamLabels(string $departmentId, array $staffIds): array
    {
        $labels = [];

        foreach (
            TeamMembership::query()
                ->active()
                ->with('team')
                ->whereIn('staff_id', $staffIds)
                ->whereHas('team', fn (Builder $query) => $query->where('department_id', $departmentId))
                ->orderBy('id')
                ->get() as $membership
        ) {
            $name = $membership->team instanceof Team ? $membership->team->name : null;

            if ($name === null) {
                continue;
            }

            $staffId = (string) $membership->staff_id;

            $labels[$staffId] = isset($labels[$staffId])
                ? $labels[$staffId].', '.$name
                : $name;
        }

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftRow(Shift $shift): array
    {
        return [
            'id' => (string) $shift->getKey(),
            'event_id' => (string) $shift->event_id,
            'department_id' => (string) $shift->department_id,
            'eligible_team_id' => $shift->eligible_team_id === null
                ? null
                : (string) $shift->eligible_team_id,
            'title' => $shift->title,
            'department_name_snapshot' => $shift->department_name_snapshot,
            'team_name_snapshot' => $shift->team_name_snapshot,
            'starts_at' => $this->moment($shift->starts_at),
            'ends_at' => $this->moment($shift->ends_at),
            'capacity' => $shift->capacity,
            'cancelled_at' => $this->moment($shift->cancelled_at),
        ];
    }
}
