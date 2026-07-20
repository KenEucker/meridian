<?php

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for restricted IMS incident list/detail reads (M11.5).
 *
 * Incident visibility is event-scoped through the configured IC department and
 * team-granted `incidents.view` capability. Organizer or department-lead status
 * alone never grants IMS incident access.
 */
final class IncidentReadAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canViewIncidents(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_INCIDENTS_VIEW,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
