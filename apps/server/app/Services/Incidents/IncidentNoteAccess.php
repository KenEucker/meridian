<?php

namespace App\Services\Incidents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Permission gate for online append-only incident notes (M11.6).
 */
final class IncidentNoteAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canAppendNote(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_INCIDENTS_ADD_NOTE,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
