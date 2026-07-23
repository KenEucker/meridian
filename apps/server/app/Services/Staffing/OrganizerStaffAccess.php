<?php

namespace App\Services\Staffing;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Organization-scoped organizer staff administration gate (M11.14).
 */
final class OrganizerStaffAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageStaff(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_STAFF_MANAGE,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team?->department !== null
                    && (string) $team->department->organization_id === (string) $organization->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
