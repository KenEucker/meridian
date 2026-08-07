<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\AttendanceRecord;
use App\Models\CurrentDeploymentAssignment;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Deployment;
use App\Models\DocumentFragment;
use App\Models\EquipmentCheckout;
use App\Models\EventDepartmentAssignment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Offline\Concerns\ComposesDocumentSections;
use App\Services\Offline\Concerns\ShapesOfflineRows;
use Illuminate\Database\Eloquent\Builder;

/**
 * The department lead's cache list of technical spec 9.3, composed for one
 * caller (SLB-001, SLB-002; TEAM-009).
 *
 * "Department Overview selected-shift summaries, exceptions, checked-in staff,
 * assignments, and compact equipment/deployment readiness identifiers.
 * Department roster. Department schedule. Department attendance data. Draft,
 * published, and archived policy/procedure documents and fragments they are
 * allowed to maintain."
 *
 * Two of those lines are about shape rather than about records.
 *
 * **Summaries and exceptions are not cached, they are derived.** The Department
 * Overview's exceptions — a shift below capacity, people not checked in after it
 * started, equipment still out — are computed from the same rows the screen
 * shows, and technical spec 20.5 keeps no exception record. A device holding the
 * computed exceptions would hold the moment they were true; holding the rows lets
 * it recompute them against its own clock, which is what an offline Overview has
 * to do to stay honest as a shift starts with nobody checked in.
 *
 * **"Compact identifiers" is taken literally.** The equipment and deployment
 * readiness a lead reads on the Overview is a count and a name, not an
 * inventory: the Equipment surface belongs to Logistics and arrives through the
 * Logistics list for the people who hold that role. So the lead's equipment
 * section is the open checkouts and nothing else, and the deployment section is
 * the current assignment and the option's name.
 *
 * The whole event's schedule travels rather than a desk horizon. A lead's
 * question is "is the department covered", which is about the shape of the
 * event; the desk's is "who is in front of me", which is about the next few
 * hours. They are different questions and they cache differently.
 */
final class DepartmentLeadSections implements OfflineReadSetContributor
{
    use ComposesDocumentSections;
    use ShapesOfflineRows;

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        $scopes = $scope->departmentScopesFor(PermissionCatalog::ROLE_DEPARTMENT_LEAD);

        if ($scopes === []) {
            return [];
        }

        $departmentIds = array_values(array_unique(array_column($scopes, 'department_id')));

        $shifts = [];
        $assignments = [];
        $attendance = [];
        $checkouts = [];
        $deployments = [];
        $deploymentOptions = [];
        $eventDepartments = [];

        foreach ($scopes as $led) {
            $eventId = $led['event_id'];
            $departmentId = $led['department_id'];

            $eventShifts = $this->shifts($eventId, $departmentId);
            $shiftIds = array_column($eventShifts, 'id');

            $shifts = [...$shifts, ...$eventShifts];
            $assignments = [...$assignments, ...$this->assignments($shiftIds)];
            $attendance = [...$attendance, ...$this->attendance($eventId, $departmentId)];
            $checkouts = [...$checkouts, ...$this->openCheckouts($eventId, $departmentId)];
            $deployments = [...$deployments, ...$this->currentDeployments($eventId, $departmentId, $shiftIds)];
            $deploymentOptions = [...$deploymentOptions, ...$this->deploymentOptions($eventId, $departmentId)];
            $eventDepartments = [...$eventDepartments, ...$this->eventAssignments($eventId, $departmentId)];
        }

        $memberships = $this->departmentMemberships($departmentIds);

        return [
            OfflineReadSetSection::core('department_lead_departments', $this->departments($departmentIds)),
            OfflineReadSetSection::core('department_lead_teams', $this->teams($departmentIds)),
            OfflineReadSetSection::core('department_lead_department_memberships', $memberships),
            OfflineReadSetSection::core('department_lead_team_memberships', $this->teamMemberships($departmentIds)),
            OfflineReadSetSection::core('department_lead_staff', $this->roster(array_column($memberships, 'staff_id'))),
            OfflineReadSetSection::core('department_lead_event_department_assignments', $this->distinct($eventDepartments)),

            OfflineReadSetSection::owned('department_lead_shifts', ModuleKey::Scheduling, $this->distinct($shifts)),
            OfflineReadSetSection::owned('department_lead_shift_assignments', ModuleKey::Scheduling, $this->distinct($assignments)),
            OfflineReadSetSection::owned('department_lead_attendance', ModuleKey::Scheduling, $this->distinct($attendance)),

            OfflineReadSetSection::owned('department_lead_equipment_checkouts', ModuleKey::Equipment, $this->distinct($checkouts)),

            OfflineReadSetSection::owned('department_lead_deployments', ModuleKey::EventGeography, $this->distinct($deploymentOptions)),
            OfflineReadSetSection::owned('department_lead_current_deployments', ModuleKey::EventGeography, $this->distinct($deployments)),

            ...$this->maintainableDocumentSections('department_lead', DocumentFragment::SCOPE_DEPARTMENT, $departmentIds),
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
     * @param  list<string>  $departmentIds
     * @return list<array<string, mixed>>
     */
    private function departments(array $departmentIds): array
    {
        return $this->rows(
            Department::query()->whereKey($departmentIds)->orderBy('name')->orderBy('id'),
            fn (Department $department): array => [
                'id' => (string) $department->getKey(),
                'organization_id' => (string) $department->organization_id,
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
                'default_team_id' => $department->default_team_id === null
                    ? null
                    : (string) $department->default_team_id,
                'archived_at' => $this->moment($department->archived_at),
            ],
        );
    }

    /**
     * @param  list<string>  $departmentIds
     * @return list<array<string, mixed>>
     */
    private function teams(array $departmentIds): array
    {
        return $this->rows(
            Team::query()->whereIn('department_id', $departmentIds)->orderBy('name')->orderBy('id'),
            fn (Team $team): array => [
                'id' => (string) $team->getKey(),
                'department_id' => (string) $team->department_id,
                'name' => $team->name,
                'code' => $team->code,
                'description' => $team->description,
                'is_default' => (bool) $team->is_default,
                'archived_at' => $this->moment($team->archived_at),
            ],
        );
    }

    /**
     * @param  list<string>  $departmentIds
     * @return list<array<string, mixed>>
     */
    private function departmentMemberships(array $departmentIds): array
    {
        return $this->rows(
            DepartmentMembership::query()
                ->active()
                ->whereIn('department_id', $departmentIds)
                ->orderBy('id'),
            fn (DepartmentMembership $membership): array => [
                'id' => (string) $membership->getKey(),
                'department_id' => (string) $membership->department_id,
                'staff_id' => (string) $membership->staff_id,
                'status' => $membership->status,
            ],
        );
    }

    /**
     * @param  list<string>  $departmentIds
     * @return list<array<string, mixed>>
     */
    private function teamMemberships(array $departmentIds): array
    {
        return $this->rows(
            TeamMembership::query()
                ->active()
                ->whereHas('team', fn (Builder $team) => $team->whereIn('department_id', $departmentIds))
                ->orderBy('id'),
            fn (TeamMembership $membership): array => [
                'id' => (string) $membership->getKey(),
                'team_id' => (string) $membership->team_id,
                'staff_id' => (string) $membership->staff_id,
                'department_membership_id' => $membership->department_membership_id === null
                    ? null
                    : (string) $membership->department_membership_id,
                'membership_role' => $membership->membership_role,
            ],
        );
    }

    /**
     * The department roster (SLB-001).
     *
     * Name and handle. A lead runs a department by knowing who is on it and what
     * to call them; the contact details M8.2 excludes are the organization's to
     * hold and `staff.me` and the organizer surfaces to serve online.
     *
     * @param  list<string>  $staffIds
     * @return list<array<string, mixed>>
     */
    private function roster(array $staffIds): array
    {
        $staffIds = array_values(array_unique($staffIds));

        if ($staffIds === []) {
            return [];
        }

        return $this->rows(
            Staff::query()->whereKey($staffIds)->orderBy('id'),
            fn (Staff $member): array => [
                'id' => (string) $member->getKey(),
                'legal_name' => $member->legal_name,
                'preferred_name' => $member->preferred_name,
                'handle' => $member->handle,
                'archived_at' => $this->moment($member->archived_at),
            ],
        );
    }

    /**
     * The department's schedule for the event (SLB-001).
     *
     * Cancelled shifts travel here, unlike at the Logistics desk. A lead reading
     * coverage needs to know a shift was cancelled rather than to find it
     * missing, and the row says so on its face.
     *
     * @return list<array<string, mixed>>
     */
    private function shifts(string $eventId, string $departmentId): array
    {
        return $this->rows(
            Shift::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->orderBy('starts_at')
                ->orderBy('id'),
            fn (Shift $shift): array => [
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
                'signup_opens_at' => $this->moment($shift->signup_opens_at),
                'signup_closes_at' => $this->moment($shift->signup_closes_at),
                'schedule_lock_at' => $this->moment($shift->schedule_lock_at),
                'cancelled_at' => $this->moment($shift->cancelled_at),
            ],
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
                'created_at' => $this->moment($assignment->created_at),
            ],
        );
    }

    /**
     * Department attendance data (technical spec 20.2).
     *
     * Scoped to the event and department rather than to the shifts above,
     * because a record survives its shift being cancelled and a lead reviewing
     * attendance is entitled to the whole picture their department produced.
     *
     * @return list<array<string, mixed>>
     */
    private function attendance(string $eventId, string $departmentId): array
    {
        return $this->rows(
            AttendanceRecord::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->orderBy('id'),
            fn (AttendanceRecord $record): array => [
                'id' => (string) $record->getKey(),
                'event_id' => (string) $record->event_id,
                'department_id' => (string) $record->department_id,
                'shift_id' => (string) $record->shift_id,
                'staff_id' => (string) $record->staff_id,
                'current_state' => $record->current_state,
                'checked_in_at' => $this->moment($record->checked_in_at),
                'checked_out_at' => $this->moment($record->checked_out_at),
                'no_show_at' => $this->moment($record->no_show_at),
            ],
        );
    }

    /**
     * The compact equipment readiness the Overview reads: what is still out, by
     * identifier.
     *
     * @return list<array<string, mixed>>
     */
    private function openCheckouts(string $eventId, string $departmentId): array
    {
        return $this->rows(
            EquipmentCheckout::query()
                ->with('equipmentItem')
                ->where('event_id', $eventId)
                ->whereNull('returned_at')
                ->whereHas('equipmentItem', fn (Builder $item) => $item->where(function (Builder $query) use ($departmentId): void {
                    $query->whereNull('department_id')->orWhere('department_id', $departmentId);
                }))
                ->orderBy('checked_out_at')
                ->orderBy('id'),
            fn (EquipmentCheckout $checkout): array => [
                'id' => (string) $checkout->getKey(),
                'equipment_item_id' => (string) $checkout->equipment_item_id,
                'staff_id' => (string) $checkout->staff_id,
                'shift_id' => $checkout->shift_id === null ? null : (string) $checkout->shift_id,
                'checked_out_at' => $this->moment($checkout->checked_out_at),
                'item_name' => $checkout->equipmentItem?->name,
                'item_asset_tag' => $checkout->equipmentItem?->asset_tag,
            ],
        );
    }

    /**
     * @param  list<string>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function currentDeployments(string $eventId, string $departmentId, array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return $this->rows(
            CurrentDeploymentAssignment::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->whereIn('shift_id', $shiftIds)
                ->orderBy('id'),
            fn (CurrentDeploymentAssignment $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'shift_id' => (string) $assignment->shift_id,
                'staff_id' => (string) $assignment->staff_id,
                'deployment_id' => (string) $assignment->deployment_id,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploymentOptions(string $eventId, string $departmentId): array
    {
        return $this->rows(
            Deployment::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->active()
                ->orderBy('name')
                ->orderBy('id'),
            fn (Deployment $deployment): array => [
                'id' => (string) $deployment->getKey(),
                'event_id' => (string) $deployment->event_id,
                'department_id' => (string) $deployment->department_id,
                'name' => $deployment->name,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventAssignments(string $eventId, string $departmentId): array
    {
        return $this->rows(
            EventDepartmentAssignment::query()
                ->where('event_id', $eventId)
                ->where('department_id', $departmentId)
                ->whereNull('archived_at')
                ->orderBy('id'),
            fn (EventDepartmentAssignment $assignment): array => [
                'id' => (string) $assignment->getKey(),
                'event_id' => (string) $assignment->event_id,
                'department_id' => (string) $assignment->department_id,
            ],
        );
    }
}
