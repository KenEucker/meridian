<?php

namespace App\Services\Shift;

use App\Models\Department;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\Departments\DepartmentSelfAdminAccess;

/**
 * Authorization for product-path shift administration (M11.17; UI contract
 * 12.4 `department.shifts`, `department.shift-create`, `department.shift-edit`).
 *
 * Department leads and department administration manage every shift in their
 * department; designated team leads manage shifts whose eligible team is a team
 * they lead.
 */
class ShiftAdminAccess
{
    public function __construct(private readonly DepartmentSelfAdminAccess $selfAdmin) {}

    public function canViewDepartmentShifts(User $user, Department $department): bool
    {
        return $this->selfAdmin->canAdministerDepartment($user, $department)
            || $this->selfAdmin->ledTeamIds($user, $department) !== [];
    }

    public function canAdministerDepartment(User $user, Department $department): bool
    {
        return $this->selfAdmin->canAdministerDepartment($user, $department);
    }

    /**
     * Teams the user may create or maintain shifts for within the department.
     *
     * @return list<string>
     */
    public function manageableTeamIds(User $user, Department $department): array
    {
        if ($this->selfAdmin->canAdministerDepartment($user, $department)) {
            return Team::query()
                ->where('department_id', $department->id)
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all();
        }

        return $this->selfAdmin->ledTeamIds($user, $department);
    }

    public function canManageShiftForTeam(User $user, Department $department, Team $team): bool
    {
        if ((string) $team->department_id !== (string) $department->id) {
            return false;
        }

        return $this->selfAdmin->canAdministerDepartment($user, $department)
            || in_array((string) $team->id, $this->selfAdmin->ledTeamIds($user, $department), true);
    }

    public function canManageShift(User $user, Shift $shift): bool
    {
        $shift->loadMissing(['department', 'eligibleTeam']);

        if ($shift->department === null || $shift->eligibleTeam === null) {
            return false;
        }

        return $this->canManageShiftForTeam($user, $shift->department, $shift->eligibleTeam);
    }
}
