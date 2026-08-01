<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may maintain an organization's incident type list (ORG-020).
 *
 * Organizers and Lead Organizers, through
 * `organization.incident_types.manage`. Incident Command roles are deliberately
 * absent: IC decides which type an incident *is*, and the organization decides
 * which types exist. Handing an IC operator the list would put the vocabulary
 * back where M18.14A took it from, one incident at a time.
 *
 * Organizer authority is organization-scoped through the configured Organizers
 * Department (technical spec 15.2), so the grant's team has to belong to the
 * organization being edited — the same check
 * {@see \App\Services\Departments\DepartmentAdminAccess} makes.
 */
final class IncidentTypeAdminAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageIncidentTypes(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_INCIDENT_TYPES_MANAGE,
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
