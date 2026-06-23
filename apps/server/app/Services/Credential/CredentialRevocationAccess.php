<?php

namespace App\Services\Credential;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Authorization for manual credential revocation (CRED-011).
 *
 * Only organizers and Incident Command leads may revoke event credentials.
 */
class CredentialRevocationAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canRevokeCredential(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            $effectiveRoles = $this->roles->resolveForStaff($staff, $event);

            foreach ($effectiveRoles as $role) {
                if (in_array($role->roleCode, [
                    PermissionCatalog::ROLE_ORGANIZER,
                    PermissionCatalog::ROLE_LEAD_ORGANIZER,
                    PermissionCatalog::ROLE_IC_LEAD,
                ], true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
