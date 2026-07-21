<?php

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for incident PDF print/export (INC-015; M11.10).
 *
 * Only roles that grant `incidents.print` (ic_lead) may print incidents to PDF.
 * IC operators and viewers may view incidents but cannot export them.
 */
final class IncidentPrintAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canPrintIncident(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_INCIDENTS_PRINT,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
