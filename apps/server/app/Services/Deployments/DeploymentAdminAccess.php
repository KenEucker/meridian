<?php

declare(strict_types=1);

namespace App\Services\Deployments;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may maintain a department's deployment options (M18.30; UI contract 12.4
 * `department.deployments`; SLB-009).
 *
 * Two capabilities, either of which is enough, which is the contract's
 * "department operations/administration as permitted" read literally:
 *
 *  - **`department.deployments.assign`** — `department_operations`. The role
 *    that stands people at these locations during the event, and the one
 *    SLB-009 gives the module to. Somebody moving staff between deployments at
 *    two in the morning is the person who discovers that the list is missing
 *    the gate they just opened, and sending them to find a lead to add it is
 *    how a location ends up unnamed all night.
 *  - **`department.administer`** — department lead and department
 *    administration. UI contract section 4.2 lists deployments among the
 *    workflows a Department Lead manages, and the list is department setup as
 *    much as it is operations.
 *
 * Assigning and maintaining are deliberately not split into two capabilities.
 * A deployment option is a name and a place; the authority that matters is over
 * where staff are standing, and `set-current-deployment` already answers to the
 * first of these. A third code would have been one more thing to grant with no
 * decision behind it.
 */
final class DeploymentAdminAccess
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        PermissionCatalog::PERMISSION_DEPARTMENT_DEPLOYMENTS_ASSIGN,
        PermissionCatalog::PERMISSION_DEPARTMENT_ADMINISTER,
    ];

    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageDeployments(User $user, Event $event, Department $department): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                $carries = false;

                foreach (self::PERMISSIONS as $permission) {
                    if (PermissionCatalog::roleHasPermission($role->roleCode, $permission)) {
                        $carries = true;

                        break;
                    }
                }

                if (! $carries) {
                    continue;
                }

                $team = Team::query()->find($role->teamId);

                if ($team === null) {
                    continue;
                }

                if ((string) $team->department_id === (string) $department->getKey()) {
                    return true;
                }
            }
        }

        return false;
    }
}
