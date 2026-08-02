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
 *
 * The three role codes were written out here until M18.5, which is the task
 * that gave the rule a screen. A surface has to decide whether to render the
 * control before any request is made, and the only authority published to a
 * client is the capability list on its session — so the rule moved into the
 * catalog as `event.credentials.revoke` and this class reads it there. The set
 * is unchanged; what changed is that the client and the node now answer from
 * one list instead of from two copies of it.
 *
 * Roles are resolved for the event, so an `ic_lead` grant scoped to another
 * event, or one on a department that is not this event's Incident Command
 * Department, does not answer here.
 */
class CredentialRevocationAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canRevokeCredential(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_EVENT_CREDENTIALS_REVOKE,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
