<?php

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for online incident creation (M11.4).
 *
 * IC creation authority is event-scoped through the configured Incident Command
 * department and is limited to ic_operator/ic_lead capability grants.
 */
final class IncidentCreationAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canCreateIncident(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_INCIDENTS_CREATE,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
