<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may maintain an organization's team designations (TEAM-016).
 *
 * Organizers and Lead Organizers, through `organization.designations.manage`.
 * Department team designations deliberately do not answer here: TEAM-016 puts
 * them on the department administration surface behind `department.administer`,
 * because which team carries a department function is the department's own
 * configuration, while which team carries Staff Coordinator authority is the
 * organization's.
 *
 * Organizer authority is organization-scoped through the configured Organizers
 * Department (technical spec 15.2), so the grant's team has to belong to the
 * organization being configured — the same check
 * {@see \App\Services\Departments\DepartmentAdminAccess} makes.
 */
final class OrganizationDesignationAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageDesignations(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_DESIGNATIONS_MANAGE,
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
