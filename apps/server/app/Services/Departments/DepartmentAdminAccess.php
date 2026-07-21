<?php

namespace App\Services\Departments;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for organization department administration (M11.12 / ORG-002).
 *
 * Organizer and lead_organizer authority is organization-scoped through the
 * configured Organizers Department (technical spec 15.2). Access requires an
 * effective role that grants organization.departments.manage whose team belongs
 * to the target organization.
 */
final class DepartmentAdminAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageDepartments(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_DEPARTMENTS_MANAGE,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team === null || $team->department === null) {
                    continue;
                }

                if ((string) $team->department->organization_id === (string) $organization->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
