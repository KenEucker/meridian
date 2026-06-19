<?php

namespace App\Services\Membership;

use App\Models\Team;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;

/**
 * Authorization for assigning staff to teams within a department (requirements
 * section 5.4; TEAM-008, TEAM-009).
 *
 * Department leads may assign staff to teams in departments they lead.
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

        return app(ApplicationReviewAccess::class)
            ->departmentLeadDepartmentIds($user)
            ->contains((string) $department->id);
    }
}
