<?php

namespace App\Services\Membership;

use App\Models\Team;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;
use App\Services\Departments\DepartmentSelfAdminAccess;

/**
 * Authorization for assigning staff to teams within a department (requirements
 * section 5.4; TEAM-008, TEAM-009).
 *
 * Department leads (and department administration) may assign staff to teams in
 * departments they administer; designated team leads may assign staff to the
 * teams they lead (M11.17).
 */
class TeamAssignmentAccess
{
    public function canAssignToTeam(User $user, Team $team): bool
    {
        if ($team->isArchived()) {
            return false;
        }

        $department = $team->relationLoaded('department')
            ? $team->department
            : $team->department()->first();

        if ($department === null || $department->isArchived()) {
            return false;
        }

        if (app(ApplicationReviewAccess::class)
            ->departmentLeadDepartmentIds($user)
            ->contains((string) $department->id)) {
            return true;
        }

        $selfAdmin = app(DepartmentSelfAdminAccess::class);

        return $selfAdmin->canAdministerDepartment($user, $department)
            || in_array((string) $team->id, $selfAdmin->ledTeamIds($user, $department), true);
    }
}
