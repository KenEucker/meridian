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
            if ((int) $team->department_id !== (int) $department->id) {
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
