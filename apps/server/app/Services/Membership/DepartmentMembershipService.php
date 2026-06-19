<?php

namespace App\Services\Membership;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DepartmentMembershipService
{
    /**
     * Assign staff to a department using the department default team only.
     *
     * Alpha 1 department assignment (APP-007) satisfies VOL-006 with the
     * structural default team; operational team assignment is delivered by M5.8.
     */
    public function assignStaffWithDefaultTeam(
        Staff $staff,
        Department $department,
        ?User $assignedBy = null,
        ?string $statusReason = null,
    ): DepartmentMembership {
        $department->loadMissing('defaultTeam');

        if ($department->defaultTeam === null) {
            throw new InvalidArgumentException('Department must have a default team before assignment.');
        }

        return $this->createWithTeams(
            $staff,
            $department,
            [$department->defaultTeam],
            DepartmentMembership::STATUS_ACTIVE,
            $statusReason ?? 'Assigned to department after application approval.',
            $assignedBy,
        );
    }

    /**
     * @param  iterable<int, Team>|Collection<int, Team>  $teams
     */
    public function createWithTeams(
        Staff $staff,
        Department $department,
        iterable $teams,
        string $status = DepartmentMembership::STATUS_ACTIVE,
        ?string $statusReason = null,
        ?User $changedBy = null,
    ): DepartmentMembership {
        $teams = collect($teams)->values();

        if ($teams->isEmpty()) {
            throw new InvalidArgumentException('Department membership requires at least one team.');
        }

        if (! in_array($status, DepartmentMembership::statuses(), true)) {
            throw new InvalidArgumentException('Unsupported department membership status.');
        }

        $teams->each(function (Team $team) use ($department): void {
            if ((string) $team->department_id !== (string) $department->id) {
                throw new InvalidArgumentException('All team memberships must belong to the assigned department.');
            }
        });

        return DB::transaction(function () use ($staff, $department, $teams, $status, $statusReason, $changedBy): DepartmentMembership {
            $departmentMembership = DepartmentMembership::query()->create([
                'department_id' => $department->id,
                'staff_id' => $staff->id,
                'status' => $status,
                'status_reason' => $statusReason,
            ]);

            $teams->each(function (Team $team) use ($staff, $departmentMembership): void {
                $departmentMembership->teamMemberships()->create([
                    'team_id' => $team->id,
                    'staff_id' => $staff->id,
                    'membership_role' => 'member',
                ]);
            });

            if ($status === DepartmentMembership::STATUS_ACTIVE) {
                app(StaffStatusService::class)->activateProspectiveOrganizationStatusForDepartmentAssignment(
                    $departmentMembership,
                    $changedBy,
                );
            }

            return $departmentMembership->refresh()->load('teamMemberships.team');
        });
    }
}
