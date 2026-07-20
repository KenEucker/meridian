<?php

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for online incident create/edit autosave updates (M11.7).
 */
final class IncidentUpdateAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canUpdateIncident(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_INCIDENTS_UPDATE,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
