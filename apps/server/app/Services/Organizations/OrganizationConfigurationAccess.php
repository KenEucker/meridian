<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Departments\DepartmentAdminAccess;
use App\Services\Permissions\EffectiveRoleResolver;
use App\Services\Teams\OrganizationDesignationAccess;

/**
 * Who may edit an organization's configuration (M18.14; ORG-020).
 *
 * Organizers and Lead Organizers, through `organization.configuration.manage`,
 * and nobody else — a Staff Coordinator reviews applications and does not set
 * the values that govern the organization's lifecycle and timing. The grant's
 * team has to belong to the organization being configured, the same
 * organization-scoping check {@see OrganizationDesignationAccess}
 * and {@see DepartmentAdminAccess} make.
 */
final class OrganizationConfigurationAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageConfiguration(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_CONFIGURATION_MANAGE,
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
