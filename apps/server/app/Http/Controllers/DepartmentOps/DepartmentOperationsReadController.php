<?php

namespace App\Http\Controllers\DepartmentOps;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\HoursWorked;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\DepartmentOps\DepartmentOperationsAccess;
use App\Services\DepartmentOps\DepartmentOperationsAuthority;
use App\Services\Presence\DepartmentPresenceException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The four department operations reads (M16.21; SLB-001 through SLB-022;
 * technical spec 20.3, 20.4, 20.5; UI contract 12.4).
 *
 * Department Overview, the Logistics Window, the Operations Center, and the
 * Planning Table were the last client surfaces still rendering a compiled-in
 * fixture. Each now has one read that fills it, scoped to one event and one
 * department, because that is the scope every one of those surfaces works in:
 * presence is per event, department, and staff member (SLB-015), and a shift
 * belongs to exactly one department (technical spec 20.5).
 *
 * Three things are decided here rather than on screen.
 *
 *  1. **Authority.** Every response carries the same `access` block, resolved
 *     from the roles the presence, attendance, equipment, and deployment
 *     commands enforce. A control the node would refuse is one the client can
 *     leave out, and the node refuses it anyway (CLIENT-006).
 *  2. **Derived state.** Shift lifecycle, whether someone may go off-site and
 *     why not, whether an unscheduled addition is even worth offering, and the
 *     plan-versus-actual arithmetic are the node's answers. The client used to
 *     hold a second copy of each, and a second copy can only drift.
 *  3. **Scope.** The Logistics Window indexes the department's staff, equipment,
 *     and shifts for the operational horizon around now; the Planning Table
 *     answers with aggregates and no identities at all (SLB-019).
 */
final class DepartmentOperationsReadController extends Controller
{
    /**
     * How far either side of now the Logistics Window's shift index reaches.
     *
     * A desk is a service station for the shift in front of it: the one running,
     * the one about to start, and the one that just ended and still has people
     * to check out and equipment to take back. Twelve hours back and thirty-six
     * forward covers an overnight handover without handing a desk every shift of
     * a ten-day event.
     */
    private const DESK_HORIZON_HOURS_BEFORE = 12;

    private const DESK_HORIZON_HOURS_AFTER = 36;

    /**
     * Department Overview for a selected shift (SLB-001, SLB-002).
     *
     * Content order is the specification's — exceptions, checked-in staff,
     * assignments, then compact equipment and deployment summaries — and it is
     * the order this payload is assembled in. `shift_id` selects; without one
     * the node picks the shift a lead opening the page is looking at, which is
     * the one running now, or the next one if none is.
     */
    public function overview(
        Request $request,
        Event $event,
        Department $department,
        DepartmentOperationsAccess $access,
    ): JsonResponse {
        $authority = $this->authorize($request, $event, $department, $access);

        if ($authority instanceof JsonResponse) {
            return $authority;
        }

        $now = Carbon::now();
        $shifts = $this->deskShifts($event, $department, $now);
        $selected = $this->selectedShift($shifts, (string) $request->query('shift_id', ''), $now);

        if ($selected === null) {
            return response()->json([
                ...$this->envelope($event, $department, $authority, $now),
                'shifts' => [],
                'selected_shift_id' => null,
                'exceptions' => [],
                'assignments' => [],
                'equipment_out' => [],
                'deployments' => $this->deploymentOptions($event, $department),
                'on_site_count' => $this->onSiteCount($event, $department),
            ]);
        }

        $assignments = $this->assignmentsForShift($selected);
        $attendance = $this->attendanceForShift($selected);
        $deployments = $this->currentDeploymentsForShift($selected);
        $equipmentOut = $this->openCheckoutsForShift($selected);

        $rows = $assignments->map(function (ShiftAssignment $assignment) use ($selected, $attendance, $deployments): array {
            $record = $attendance[(string) $assignment->staff_id] ?? null;

            return [
                'assignment_id' => (string) $assignment->id,
                'staff_id' => (string) $assignment->staff_id,
                'display_name' => $this->staffName($assignment->staff),
                'handle' => $assignment->staff?->handle,
                'team_label' => $selected->eligibleTeam?->name ?? $selected->team_name_snapshot,
                'attendance_state' => $record?->current_state ?? AttendanceRecord::STATE_SCHEDULED,
                'checked_in_at' => $record?->checked_in_at?->toIso8601String(),
                'current_deployment_id' => $deployments[(string) $assignment->staff_id] ?? null,
                // An assignment created after the shift started is the
                // unscheduled addition SLB-008 allows; Alpha 1 records no
                // separate exception for it (technical spec 20.5).
                'unscheduled' => $selected->starts_at !== null
                    && $assignment->created_at !== null
                    && $assignment->created_at->greaterThanOrEqualTo($selected->starts_at),
            ];
        });

        return response()->json([
            ...$this->envelope($event, $department, $authority, $now),
            'shifts' => $shifts->map(fn (Shift $shift): array => $this->shiftOption($shift, $now))->values()->all(),
            'selected_shift_id' => (string) $selected->id,
            'exceptions' => $this->exceptionsFor($selected, $rows->all(), $equipmentOut, $now),
            'assignments' => $rows->values()->all(),
            'equipment_out' => $equipmentOut
                ->map(fn (EquipmentCheckout $checkout): array => [
                    'checkout_id' => (string) $checkout->id,
                    'item_name' => $checkout->equipmentItem?->name ?? 'Equipment',
                    'asset_tag' => $checkout->equipmentItem?->asset_tag,
                    'staff_name' => $this->staffName($checkout->staff),
                    'checked_out_at' => $checkout->checked_out_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'deployments' => $this->deploymentOptions($event, $department),
            'on_site_count' => $this->onSiteCount($event, $department),
        ]);
    }

    /**
     * The Logistics Window's department-scoped index (SLB-003 through SLB-008,
     * SLB-011, SLB-012, SLB-016 through SLB-018, SLB-021).
     *
     * Staff-first: the search index and one workspace per department member,
     * because the desk's whole shape is "someone is standing here, find them and
     * act". Building every workspace in the one read is what lets the search
     * answer from what the device already holds rather than a request per
     * person.
     */
    public function logistics(
        Request $request,
        Event $event,
        Department $department,
        DepartmentOperationsAccess $access,
    ): JsonResponse {
        $authority = $this->authorize($request, $event, $department, $access);

        if ($authority instanceof JsonResponse) {
            return $authority;
        }

        $now = Carbon::now();
        $staff = $this->departmentStaff($department);
        $staffIds = $staff->map(fn (Staff $member): string => (string) $member->id)->all();
        $shifts = $this->deskShifts($event, $department, $now);
        $teamLabels = $this->teamLabels($department, $staffIds);
        $presence = $this->presenceStates($event, $department, $staffIds);
        $assignments = $this->assignmentsForShifts($shifts->modelKeys(), $staffIds);
        $attendance = $this->attendanceForShifts($event, $department, $shifts->modelKeys(), $staffIds);
        $openEquipment = $this->openCheckoutsForStaff($event, $department, $staffIds);
        $availableEquipment = $this->availableEquipment($department);
        $signups = $this->futureSignups($event, $department, $staffIds, $now);
        $eligibleTeamMembers = $this->eligibleTeamMembers($shifts, $staffIds);

        $workspaces = [];

        foreach ($staff as $member) {
            $staffId = (string) $member->id;
            $held = $openEquipment->get($staffId, new Collection);
            $checkedIn = $attendance->get($staffId, new Collection)
                ->contains(fn (AttendanceRecord $record): bool => $record->current_state === AttendanceRecord::STATE_CHECKED_IN);

            $workspaces[$staffId] = [
                'staff_id' => $staffId,
                'display_name' => $this->staffName($member),
                'handle' => $member->handle,
                'team_label' => $teamLabels[$staffId] ?? $department->name,
                'presence_state' => $presence[$staffId] ?? EventDepartmentPresence::STATE_OFF_SITE,
                // The same two blocks `DepartmentPresenceService` enforces, in
                // the words its refusal uses (SLB-017, SLB-018).
                'can_go_off_site' => ! $checkedIn && $held->isEmpty(),
                'off_site_blocked_reason' => match (true) {
                    $checkedIn => DepartmentPresenceException::checkedInToShift()->getMessage(),
                    $held->isNotEmpty() => DepartmentPresenceException::openEquipmentCheckout()->getMessage(),
                    default => null,
                },
                'shift_cards' => $this->shiftCards(
                    $shifts,
                    $staffId,
                    $assignments,
                    $attendance,
                    $presence[$staffId] ?? EventDepartmentPresence::STATE_OFF_SITE,
                    $eligibleTeamMembers,
                    $now,
                ),
                'open_equipment' => $held
                    ->map(fn (EquipmentCheckout $checkout): array => [
                        'checkout_id' => (string) $checkout->id,
                        'equipment_item_id' => (string) $checkout->equipment_item_id,
                        'name' => $checkout->equipmentItem?->name ?? 'Equipment',
                        'asset_tag' => $checkout->equipmentItem?->asset_tag,
                        'status' => $checkout->equipmentItem?->status ?? EquipmentItem::STATUS_CHECKED_OUT,
                        'checked_out_at' => $checkout->checked_out_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
                'available_equipment' => $availableEquipment,
                'future_signups' => $signups->get($staffId, new Collection)
                    ->map(fn (ShiftAssignment $assignment): array => [
                        'signup_id' => (string) $assignment->id,
                        'shift_id' => (string) $assignment->shift_id,
                        'shift_title' => $assignment->shift?->title ?? 'Shift',
                        'starts_at' => $assignment->shift?->starts_at?->toIso8601String(),
                        'ends_at' => $assignment->shift?->ends_at?->toIso8601String(),
                        'state' => $assignment->assignment_status,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $holders = $openEquipment
            ->flatMap(fn (Collection $checkouts): array => $checkouts->all())
            ->keyBy(fn (EquipmentCheckout $checkout): string => (string) $checkout->equipment_item_id);

        return response()->json([
            ...$this->envelope($event, $department, $authority, $now),
            'searchable_staff' => collect($workspaces)
                ->map(fn (array $workspace): array => [
                    'staff_id' => $workspace['staff_id'],
                    'display_name' => $workspace['display_name'],
                    'handle' => $workspace['handle'],
                    'team_label' => $workspace['team_label'],
                    'presence_state' => $workspace['presence_state'],
                ])
                ->values()
                ->all(),
            'searchable_equipment' => $this->departmentEquipment($department)
                ->map(function (EquipmentItem $item) use ($holders): array {
                    $checkout = $holders->get((string) $item->id);

                    return [
                        'equipment_item_id' => (string) $item->id,
                        'name' => $item->name,
                        'asset_tag' => $item->asset_tag,
                        'status' => $item->status,
                        'status_label' => EquipmentItem::statusLabel($item->status),
                        'holder_staff_id' => $checkout === null ? null : (string) $checkout->staff_id,
                        'holder_name' => $checkout === null ? null : $this->staffName($checkout->staff),
                    ];
                })
                ->values()
                ->all(),
            'searchable_shifts' => $shifts->map(fn (Shift $shift): array => $this->shiftOption($shift, $now))->values()->all(),
            'staff_workspaces' => (object) $workspaces,
        ]);
    }

    /**
     * The Operations Center's deployment module (SLB-009, SLB-010, SLB-022).
     *
     * Deployment options and the staff currently on shift who may be moved
     * between them. The other modules on that screen — Field Reports, incidents,
     * equipment — are composed from capabilities the actor already holds and
     * read their own endpoints; the shell grants nothing (SLB-014, SLB-022),
     * which is why this read publishes only what deployments need plus the
     * access block those modules are composed from.
     */
    public function operations(
        Request $request,
        Event $event,
        Department $department,
        DepartmentOperationsAccess $access,
    ): JsonResponse {
        $authority = $this->authorize($request, $event, $department, $access);

        if ($authority instanceof JsonResponse) {
            return $authority;
        }

        $now = Carbon::now();
        $shifts = $this->deskShifts($event, $department, $now)
            ->filter(fn (Shift $shift): bool => $this->lifecycle($shift, $now) === 'active');
        $deployments = $this->currentDeploymentsForShifts($shifts->modelKeys());
        $options = $this->deploymentOptions($event, $department);
        $names = collect($options)->pluck('name', 'id');

        $rows = ShiftAssignment::query()
            ->with(['staff', 'shift'])
            ->whereIn('shift_id', $shifts->modelKeys())
            ->whereNull('removed_at')
            ->get()
            ->map(function (ShiftAssignment $assignment) use ($deployments, $names): array {
                $deploymentId = $deployments[(string) $assignment->shift_id.':'.(string) $assignment->staff_id] ?? null;

                return [
                    'assignment_id' => (string) $assignment->id,
                    'staff_id' => (string) $assignment->staff_id,
                    'display_name' => $this->staffName($assignment->staff),
                    'shift_id' => (string) $assignment->shift_id,
                    'shift_title' => $assignment->shift?->title ?? 'Shift',
                    'current_deployment_id' => $deploymentId,
                    'current_deployment_name' => $deploymentId === null ? null : $names->get($deploymentId),
                ];
            })
            ->sortBy('display_name')
            ->values()
            ->all();

        return response()->json([
            ...$this->envelope($event, $department, $authority, $now),
            'deployments' => $options,
            'rows' => $rows,
            'equipment_out_count' => $this->openCheckoutsForStaff(
                $event,
                $department,
                $this->departmentStaff($department)->modelKeys(),
            )->flatten()->count(),
        ]);
    }

    /**
     * The Planning Table (SLB-019, SLB-020).
     *
     * Aggregates only. No staff id, no name, no assignment id, and no signup
     * list reaches this payload — the row is the shift, and the counts on it are
     * counts. `team_id` and `date` narrow the view without changing what a
     * caller may see, which is the whole of SLB-020.
     */
    public function planning(
        Request $request,
        Event $event,
        Department $department,
        DepartmentOperationsAccess $access,
    ): JsonResponse {
        $authority = $this->authorize($request, $event, $department, $access);

        if ($authority instanceof JsonResponse) {
            return $authority;
        }

        $now = Carbon::now();
        $teamId = $this->nullableQuery($request, 'team_id');
        $date = $this->nullableQuery($request, 'date');
        $timezone = $event->timezone ?: config('app.timezone');

        $query = Shift::query()
            ->with('eligibleTeam')
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->orderBy('starts_at');

        if ($teamId !== null) {
            $query->where('eligible_team_id', $teamId);
        }

        $shifts = $query->get();

        if ($date !== null) {
            $shifts = $shifts->filter(
                fn (Shift $shift): bool => $shift->starts_at !== null
                    && $shift->starts_at->copy()->setTimezone($timezone)->toDateString() === $date,
            );
        }

        $shiftIds = $shifts->modelKeys();
        $assignments = $this->assignmentCounts($shiftIds);
        $attendance = $this->attendanceCounts($shiftIds);
        $actualMinutes = $this->actualMinutes($shiftIds);

        return response()->json([
            ...$this->envelope($event, $department, $authority, $now),
            'teams' => Team::query()
                ->where('department_id', $department->id)
                ->active()
                ->orderBy('name')
                ->get()
                ->map(fn (Team $team): array => [
                    'team_id' => (string) $team->id,
                    'team_label' => $team->name,
                ])
                ->values()
                ->all(),
            'filters' => [
                'team_id' => $teamId,
                'date' => $date,
            ],
            'rows' => $shifts
                ->map(fn (Shift $shift): array => $this->planningRow(
                    $shift,
                    $now,
                    $assignments[(string) $shift->id] ?? ['total' => 0, 'unscheduled' => 0],
                    $attendance[(string) $shift->id] ?? ['checked_in' => 0, 'no_show' => 0],
                    $actualMinutes[(string) $shift->id] ?? 0,
                ))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The caller's standing, or the refusal that stands in for the surface.
     *
     * A department outside the event's organization is a 404 rather than a 403:
     * the pair does not describe anything, so there is nothing to be refused
     * access to.
     */
    private function authorize(
        Request $request,
        Event $event,
        Department $department,
        DepartmentOperationsAccess $access,
    ): DepartmentOperationsAuthority|JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $department->organization_id !== (string) $event->organization_id) {
            return response()->json([
                'message' => 'Department not found for this event.',
            ], 404);
        }

        $authority = $access->resolve($user, $event, $department);

        if (! $authority->canViewOperations()) {
            return response()->json([
                'message' => 'You do not have permission to view department operations for this event.',
            ], 403);
        }

        return $authority;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(
        Event $event,
        Department $department,
        DepartmentOperationsAuthority $authority,
        Carbon $now,
    ): array {
        return [
            'context' => [
                'event_id' => (string) $event->id,
                'event_label' => $event->name,
                'department_id' => (string) $department->id,
                'department_label' => $department->name,
                'time_zone' => $event->timezone ?: config('app.timezone'),
                'as_of' => $now->toIso8601String(),
            ],
            'access' => $authority->toArray(),
        ];
    }

    /**
     * The department's shifts inside the desk horizon, oldest first.
     *
     * Cancelled shifts are left out. Nobody is expected at one, no attendance
     * may be recorded against one (`UnscheduledShiftAdditionException`,
     * SLB-027), and a desk offering it could only mislead.
     *
     * @return Collection<int, Shift>
     */
    private function deskShifts(Event $event, Department $department, Carbon $now): Collection
    {
        return Shift::query()
            ->with('eligibleTeam')
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->where('ends_at', '>=', $now->copy()->subHours(self::DESK_HORIZON_HOURS_BEFORE))
            ->where('starts_at', '<=', $now->copy()->addHours(self::DESK_HORIZON_HOURS_AFTER))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     */
    private function selectedShift(Collection $shifts, string $requested, Carbon $now): ?Shift
    {
        if ($requested !== '') {
            return $shifts->firstWhere('id', $requested);
        }

        return $shifts->first(fn (Shift $shift): bool => $this->lifecycle($shift, $now) === 'active')
            ?? $shifts->first(fn (Shift $shift): bool => $this->lifecycle($shift, $now) === 'upcoming')
            ?? $shifts->last();
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftOption(Shift $shift, Carbon $now): array
    {
        return [
            'shift_id' => (string) $shift->id,
            'title' => $shift->title,
            'team_id' => (string) $shift->eligible_team_id,
            'team_label' => $shift->eligibleTeam?->name ?? $shift->team_name_snapshot,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'lifecycle' => $this->lifecycle($shift, $now),
            'capacity' => $shift->capacity,
        ];
    }

    private function lifecycle(Shift $shift, Carbon $now): string
    {
        if ($shift->isCancelled()) {
            return 'cancelled';
        }

        if ($shift->starts_at !== null && $now->lessThan($shift->starts_at)) {
            return 'upcoming';
        }

        if ($shift->ends_at !== null && $now->greaterThan($shift->ends_at)) {
            return 'completed';
        }

        return 'active';
    }

    /**
     * What a lead needs to look at first (SLB-002).
     *
     * Three, and each is a fact about this shift rather than a judgement:
     * fewer people assigned than the shift asks for, people still not checked in
     * after it started, and equipment still out. Nothing here is stored — an
     * exception record is not an Alpha 1 concept (technical spec 20.5) — so each
     * is derived from the same rows the sections below it show.
     *
     * @param  list<array<string, mixed>>  $assignments
     * @param  Collection<int, EquipmentCheckout>  $equipmentOut
     * @return list<array<string, mixed>>
     */
    private function exceptionsFor(Shift $shift, array $assignments, Collection $equipmentOut, Carbon $now): array
    {
        $exceptions = [];
        $assigned = count($assignments);

        if ($shift->capacity !== null && $assigned < $shift->capacity) {
            $short = $shift->capacity - $assigned;

            $exceptions[] = [
                'id' => 'coverage',
                'severity' => 'warning',
                'label' => 'Coverage gap',
                'detail' => sprintf(
                    '%s is %d below its capacity of %d.',
                    $shift->title,
                    $short,
                    $shift->capacity,
                ),
            ];
        }

        $awaiting = array_filter(
            $assignments,
            fn (array $row): bool => $row['attendance_state'] === AttendanceRecord::STATE_SCHEDULED,
        );

        if ($awaiting !== [] && $shift->starts_at !== null && $now->greaterThanOrEqualTo($shift->starts_at)) {
            $exceptions[] = [
                'id' => 'awaiting-check-in',
                'severity' => 'warning',
                'label' => 'Not checked in',
                'detail' => sprintf(
                    '%d assigned staff %s not checked in since the shift started.',
                    count($awaiting),
                    count($awaiting) === 1 ? 'is' : 'are',
                ),
            ];
        }

        if ($equipmentOut->isNotEmpty()) {
            $exceptions[] = [
                'id' => 'equipment-out',
                'severity' => 'attention',
                'label' => 'Equipment still out',
                'detail' => sprintf(
                    '%d item%s checked out against this shift.',
                    $equipmentOut->count(),
                    $equipmentOut->count() === 1 ? '' : 's',
                ),
            ];
        }

        return $exceptions;
    }

    /**
     * @return Collection<int, ShiftAssignment>
     */
    private function assignmentsForShift(Shift $shift): Collection
    {
        return ShiftAssignment::query()
            ->with('staff')
            ->where('shift_id', $shift->id)
            ->whereNull('removed_at')
            ->get()
            ->sortBy(fn (ShiftAssignment $assignment): string => $this->staffName($assignment->staff))
            ->values();
    }

    /**
     * @return array<string, AttendanceRecord>
     */
    private function attendanceForShift(Shift $shift): array
    {
        return AttendanceRecord::query()
            ->where('shift_id', $shift->id)
            ->get()
            ->keyBy(fn (AttendanceRecord $record): string => (string) $record->staff_id)
            ->all();
    }

    /**
     * @param  list<mixed>  $shiftIds
     * @param  list<string>  $staffIds
     * @return Collection<string, Collection<int, ShiftAssignment>>
     */
    private function assignmentsForShifts(array $shiftIds, array $staffIds): Collection
    {
        if ($shiftIds === [] || $staffIds === []) {
            return new Collection;
        }

        return ShiftAssignment::query()
            ->whereIn('shift_id', $shiftIds)
            ->whereIn('staff_id', $staffIds)
            ->whereNull('removed_at')
            ->get()
            ->groupBy(fn (ShiftAssignment $assignment): string => (string) $assignment->staff_id.':'.(string) $assignment->shift_id);
    }

    /**
     * @param  list<mixed>  $shiftIds
     * @param  list<string>  $staffIds
     * @return Collection<string, Collection<int, AttendanceRecord>>
     */
    private function attendanceForShifts(Event $event, Department $department, array $shiftIds, array $staffIds): Collection
    {
        if ($staffIds === []) {
            return new Collection;
        }

        return AttendanceRecord::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->whereIn('staff_id', $staffIds)
            ->when($shiftIds !== [], fn (Builder $query) => $query->whereIn('shift_id', $shiftIds))
            ->get()
            ->groupBy(fn (AttendanceRecord $record): string => (string) $record->staff_id);
    }

    /**
     * The staff-facing shift cards for one department member.
     *
     * A card exists for every shift in the desk horizon, whether or not this
     * person is on it, because the desk's other job is adding an on-site staff
     * member to a shift they were never assigned to (SLB-008). What differs per
     * card is which of the three actions it offers, and each of those is the
     * same condition the command enforces.
     *
     * `can_add_to_shift` is the one approximation, and deliberately the loose
     * side of one: it checks presence, an existing assignment, that the shift
     * has started, and eligible-team membership. `UnscheduledShiftAdditionService`
     * additionally weighs trainings, waivers, and organization status, and will
     * refuse in its own words — which is better than a desk that silently offers
     * nothing and leaves an operator wondering.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<string, Collection<int, ShiftAssignment>>  $assignments
     * @param  Collection<string, Collection<int, AttendanceRecord>>  $attendance
     * @param  array<string, bool>  $eligibleTeamMembers
     * @return list<array<string, mixed>>
     */
    private function shiftCards(
        Collection $shifts,
        string $staffId,
        Collection $assignments,
        Collection $attendance,
        string $presenceState,
        array $eligibleTeamMembers,
        Carbon $now,
    ): array {
        $records = $attendance->get($staffId, new Collection)
            ->keyBy(fn (AttendanceRecord $record): string => (string) $record->shift_id);
        $onSite = $presenceState === EventDepartmentPresence::STATE_ON_SITE;

        return $shifts
            ->map(function (Shift $shift) use ($staffId, $assignments, $records, $onSite, $eligibleTeamMembers, $now): ?array {
                $shiftId = (string) $shift->id;
                $assignment = $assignments->get($staffId.':'.$shiftId, new Collection)->first();
                $record = $records->get($shiftId);
                $state = $record?->current_state;
                $lifecycle = $this->lifecycle($shift, $now);
                $started = $shift->starts_at !== null && $now->greaterThanOrEqualTo($shift->starts_at);
                $eligible = $eligibleTeamMembers[$staffId.':'.(string) $shift->eligible_team_id] ?? false;

                if ($assignment === null && ! ($onSite && $started && $eligible)) {
                    return null;
                }

                return [
                    'shift_id' => $shiftId,
                    'title' => $shift->title,
                    'team_id' => (string) $shift->eligible_team_id,
                    'team_label' => $shift->eligibleTeam?->name ?? $shift->team_name_snapshot,
                    'starts_at' => $shift->starts_at?->toIso8601String(),
                    'ends_at' => $shift->ends_at?->toIso8601String(),
                    'lifecycle' => $lifecycle,
                    'attendance_state' => $assignment === null
                        ? null
                        : ($state ?? AttendanceRecord::STATE_SCHEDULED),
                    'assignment_id' => $assignment === null ? null : (string) $assignment->id,
                    // Check-in follows the shift, not the clock: technical spec
                    // 20.2 has Logistics check somebody in once they are on-site
                    // for the department, and 20.5 allows a check-in and
                    // check-out recorded together after the fact.
                    'can_check_in' => $assignment !== null
                        && $onSite
                        && $state !== AttendanceRecord::STATE_CHECKED_IN
                        && $state !== AttendanceRecord::STATE_CHECKED_OUT,
                    'can_check_out' => $assignment !== null
                        && $state === AttendanceRecord::STATE_CHECKED_IN,
                    // No-show only applies after a shift has started
                    // (technical spec 20.5) and only to somebody who has not
                    // arrived.
                    'can_mark_no_show' => $assignment !== null
                        && $started
                        && ($state === null || $state === AttendanceRecord::STATE_SCHEDULED),
                    'can_add_to_shift' => $assignment === null && $onSite && $started && $eligible,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Which of these staff members belong to each shift's eligible team.
     *
     * Keyed `staffId:teamId` so a card can answer without a query of its own.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  list<string>  $staffIds
     * @return array<string, bool>
     */
    private function eligibleTeamMembers(Collection $shifts, array $staffIds): array
    {
        $teamIds = $shifts->pluck('eligible_team_id')->filter()->unique()->values()->all();

        if ($teamIds === [] || $staffIds === []) {
            return [];
        }

        $members = [];

        foreach (
            TeamMembership::query()
                ->active()
                ->whereIn('team_id', $teamIds)
                ->whereIn('staff_id', $staffIds)
                ->get() as $membership
        ) {
            $members[(string) $membership->staff_id.':'.(string) $membership->team_id] = true;
        }

        return $members;
    }

    /**
     * @return Collection<int, Staff>
     */
    private function departmentStaff(Department $department): Collection
    {
        return Staff::query()
            ->whereIn('id', DepartmentMembership::query()
                ->active()
                ->where('department_id', $department->id)
                ->where('status', DepartmentMembership::STATUS_ACTIVE)
                ->select('staff_id'))
            ->orderBy('legal_name')
            ->get();
    }

    /**
     * @param  list<string>  $staffIds
     * @return array<string, string>
     */
    private function teamLabels(Department $department, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        $labels = [];

        foreach (
            TeamMembership::query()
                ->active()
                ->with('team')
                ->whereIn('staff_id', $staffIds)
                ->whereHas('team', fn (Builder $query) => $query->where('department_id', $department->id))
                ->get() as $membership
        ) {
            $staffId = (string) $membership->staff_id;
            $name = $membership->team?->name;

            if ($name === null) {
                continue;
            }

            // A staff member on several teams is labelled with all of them; the
            // desk shows one line and the operator should see the whole answer.
            $labels[$staffId] = isset($labels[$staffId])
                ? $labels[$staffId].', '.$name
                : $name;
        }

        return $labels;
    }

    /**
     * @param  list<string>  $staffIds
     * @return array<string, string>
     */
    private function presenceStates(Event $event, Department $department, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        return EventDepartmentPresence::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->whereIn('staff_id', $staffIds)
            ->get()
            ->mapWithKeys(fn (EventDepartmentPresence $presence): array => [
                (string) $presence->staff_id => (string) $presence->current_state,
            ])
            ->all();
    }

    private function onSiteCount(Event $event, Department $department): int
    {
        return EventDepartmentPresence::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->onSite()
            ->count();
    }

    /**
     * @param  list<string>  $staffIds
     * @return Collection<string, Collection<int, EquipmentCheckout>>
     */
    private function openCheckoutsForStaff(Event $event, Department $department, array $staffIds): Collection
    {
        if ($staffIds === []) {
            return new Collection;
        }

        return EquipmentCheckout::query()
            ->with(['equipmentItem', 'staff'])
            ->where('event_id', $event->id)
            ->whereIn('staff_id', $staffIds)
            ->whereNull('returned_at')
            ->whereHas('equipmentItem', fn (Builder $query) => $query->where(function (Builder $scope) use ($department): void {
                $scope->whereNull('department_id')->orWhere('department_id', $department->id);
            }))
            ->get()
            ->groupBy(fn (EquipmentCheckout $checkout): string => (string) $checkout->staff_id);
    }

    /**
     * @return Collection<int, EquipmentCheckout>
     */
    private function openCheckoutsForShift(Shift $shift): Collection
    {
        return EquipmentCheckout::query()
            ->with(['equipmentItem', 'staff'])
            ->where('shift_id', $shift->id)
            ->whereNull('returned_at')
            ->get();
    }

    /**
     * @return Collection<int, EquipmentItem>
     */
    private function departmentEquipment(Department $department): Collection
    {
        return EquipmentItem::query()
            ->where('department_id', $department->id)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * What the desk may hand out right now.
     *
     * The same list for every workspace, because an available item is available
     * to whoever is standing at the desk. It is carried on each workspace rather
     * than beside them so the check-in and checkout dialogs read one object.
     *
     * @return list<array<string, mixed>>
     */
    private function availableEquipment(Department $department): array
    {
        return EquipmentItem::query()
            ->where('department_id', $department->id)
            ->active()
            ->where('status', EquipmentItem::STATUS_AVAILABLE)
            ->orderBy('name')
            ->get()
            ->map(fn (EquipmentItem $item): array => [
                'checkout_id' => null,
                'equipment_item_id' => (string) $item->id,
                'name' => $item->name,
                'asset_tag' => $item->asset_tag,
                'status' => $item->status,
                'checked_out_at' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $staffIds
     * @return Collection<string, Collection<int, ShiftAssignment>>
     */
    private function futureSignups(Event $event, Department $department, array $staffIds, Carbon $now): Collection
    {
        if ($staffIds === []) {
            return new Collection;
        }

        return ShiftAssignment::query()
            ->with('shift')
            ->whereIn('staff_id', $staffIds)
            ->whereNull('removed_at')
            ->whereHas('shift', fn (Builder $query) => $query
                ->where('event_id', $event->id)
                ->where('department_id', $department->id)
                ->active()
                ->where('starts_at', '>', $now))
            ->get()
            ->sortBy(fn (ShiftAssignment $assignment): string => (string) $assignment->shift?->starts_at?->toIso8601String())
            ->groupBy(fn (ShiftAssignment $assignment): string => (string) $assignment->staff_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploymentOptions(Event $event, Department $department): array
    {
        return Deployment::query()
            ->where('event_id', $event->id)
            ->where('department_id', $department->id)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (Deployment $deployment): array => [
                'id' => (string) $deployment->id,
                'name' => $deployment->name,
                'description' => $deployment->description,
                'location_details' => $deployment->location_details,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function currentDeploymentsForShift(Shift $shift): array
    {
        $assignments = $this->currentDeploymentsForShifts([$shift->id]);
        $deployments = [];

        foreach ($assignments as $key => $deploymentId) {
            [, $staffId] = explode(':', $key, 2);
            $deployments[$staffId] = $deploymentId;
        }

        return $deployments;
    }

    /**
     * Current deployment per shift and staff member, keyed `shiftId:staffId`.
     *
     * One per pair: `DeploymentAssignmentService` keeps exactly one current
     * assignment for a shift and staff member (SLB-010, technical spec 20.5).
     *
     * @param  list<mixed>  $shiftIds
     * @return array<string, string>
     */
    private function currentDeploymentsForShifts(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $deployments = [];

        foreach (
            CurrentDeploymentAssignment::query()
                ->whereIn('shift_id', $shiftIds)
                ->get() as $assignment
        ) {
            $deployments[(string) $assignment->shift_id.':'.(string) $assignment->staff_id]
                = (string) $assignment->deployment_id;
        }

        return $deployments;
    }

    /**
     * @param  list<mixed>  $shiftIds
     * @return array<string, array{total: int, unscheduled: int}>
     */
    private function assignmentCounts(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

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
        if ($shiftIds === []) {
            return [];
        }

        $counts = [];

        foreach (
            AttendanceRecord::query()->whereIn('shift_id', $shiftIds)->get() as $record
        ) {
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
        if ($shiftIds === []) {
            return [];
        }

        return HoursWorked::query()
            ->whereIn('shift_id', $shiftIds)
            ->selectRaw('shift_id, sum(minutes_worked) as minutes')
            ->groupBy('shift_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->shift_id => (int) $row->minutes])
            ->all();
    }

    /**
     * One identity-free plan-versus-actual row (SLB-019).
     *
     * Planned hours are what the shift asked for: its capacity across its own
     * window, or — where it sets no capacity target — the people actually on it,
     * because a shift with no target cannot be under one.
     *
     * @param  array{total: int, unscheduled: int}  $assignments
     * @param  array{checked_in: int, no_show: int}  $attendance
     * @return array<string, mixed>
     */
    private function planningRow(
        Shift $shift,
        Carbon $now,
        array $assignments,
        array $attendance,
        int $actualMinutes,
    ): array {
        $lifecycle = $this->lifecycle($shift, $now);
        $durationHours = $shift->starts_at !== null && $shift->ends_at !== null
            ? $shift->starts_at->diffInMinutes($shift->ends_at) / 60
            : 0.0;
        $plannedHours = round(($shift->capacity ?? $assignments['total']) * $durationHours, 1);
        $actualHours = round($actualMinutes / 60, 1);

        return [
            'shift_id' => (string) $shift->id,
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
            'status_label' => $this->planningStatusLabel(
                $lifecycle,
                $shift->capacity,
                $assignments['total'],
                $plannedHours,
                $actualHours,
            ),
        ];
    }

    private function planningStatusLabel(
        string $lifecycle,
        ?int $capacity,
        int $assigned,
        float $plannedHours,
        float $actualHours,
    ): string {
        return match (true) {
            $lifecycle === 'cancelled' => 'Cancelled',
            $lifecycle === 'upcoming' && $capacity !== null && $assigned < $capacity => 'Under target',
            $lifecycle === 'upcoming' => 'Upcoming',
            $lifecycle === 'completed' && $actualHours > $plannedHours => 'Completed over plan',
            $lifecycle === 'completed' => 'Completed under plan',
            $capacity !== null && $assigned < $capacity => 'Under target',
            default => 'On plan',
        };
    }

    private function staffName(?Staff $staff): string
    {
        if ($staff === null) {
            return 'Staff member';
        }

        return $staff->preferred_name !== null && $staff->preferred_name !== ''
            ? $staff->preferred_name
            : $staff->legal_name;
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : $value;
    }
}
