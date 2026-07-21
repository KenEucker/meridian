<?php

namespace App\Services\Departments;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for department self-administration (M11.13).
 *
 * Department lead and department_administration authority is department-scoped
 * through team grants (technical spec 15.2). Access requires an effective role
 * that grants department.administer whose team belongs to the target department.
 */
final class DepartmentSelfAdminAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canAdministerDepartment(User $user, Department $department): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_DEPARTMENT_ADMINISTER,
                )) {
                    continue;
                }

                $team = Team::query()->find($role->teamId);

                if ($team === null) {
                    continue;
                }

                if ((string) $team->department_id === (string) $department->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
