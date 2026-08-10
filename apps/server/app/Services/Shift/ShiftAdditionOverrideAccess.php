<?php

namespace App\Services\Shift;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Event;
use App\Models\PermissionRole;
use App\Models\User;
use App\Services\Permissions\DepartmentOperationalAccess;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may override a refused unscheduled shift addition (M18.55; CLIENT-017A).
 *
 * One capability — `department.shift_additions.override` — held at two
 * different scopes, which is why this is a class rather than a line inside
 * `AttendanceCheckInAccess`:
 *
 *   - **Department scope.** A department lead or a department administrator
 *     resolves through a grant on a team *in the department the shift belongs
 *     to*, which is what `DepartmentOperationalAccess` already answers and what
 *     keeps the lead of one department out of another's roster.
 *   - **Organization scope.** An organizer's grant sits on a team in the
 *     Organizers Department and would never resolve as a department grant, so
 *     it is asked for through the effective-role resolver instead — and only
 *     against staff profiles holding standing in the event's own organization,
 *     because a grant is only an authority somewhere.
 *
 * Which roles are asked for at which scope is not written out here. It is read
 * from the catalog: the capability's roles, split by the scope the catalog says
 * each role resolves at. A role added to the capability later is therefore
 * covered by whichever branch its own scope puts it in, and this class does not
 * have to be edited to find out about it.
 *
 * What this class deliberately does *not* decide is which refusals may be
 * overridden. That is `ShiftAdditionRefusalReason`'s, holds at every authority,
 * and is checked separately — so "who may override" and "what may be overridden"
 * cannot be confused for one another by a caller that only remembers to ask one.
 */
class ShiftAdditionOverrideAccess
{
    public function __construct(
        private readonly DepartmentOperationalAccess $departmentAccess,
        private readonly EffectiveRoleResolver $roles,
    ) {}

    public function canOverrideShiftAddition(User $user, Event $event, Department $department): bool
    {
        if ($department->isArchived()) {
            return false;
        }

        return $this->holdsDepartmentScopedOverride($user, $event, $department)
            || $this->holdsOrganizationScopedOverride($user, $event);
    }

    private function holdsDepartmentScopedOverride(User $user, Event $event, Department $department): bool
    {
        $roleCodes = $this->roleCodesAtScopes([
            PermissionRole::SCOPE_DEPARTMENT,
            PermissionRole::SCOPE_TEAM,
        ]);

        return $roleCodes !== []
            && $this->departmentAccess->hasDepartmentRole($user, $event, $department, $roleCodes);
    }

    private function holdsOrganizationScopedOverride(User $user, Event $event): bool
    {
        $roleCodes = $this->roleCodesAtScopes([
            PermissionRole::SCOPE_ORGANIZATION,
            PermissionRole::SCOPE_EVENT,
        ]);

        if ($roleCodes === []) {
            return false;
        }

        $staffProfiles = $user->staffProfiles()
            ->inOrganization((string) $event->organization_id)
            ->get();

        foreach ($staffProfiles as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (in_array($role->roleCode, $roleCodes, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The capability's roles that resolve at any of the given scopes.
     *
     * @param  list<string>  $scopeTypes
     * @return list<string>
     */
    private function roleCodesAtScopes(array $scopeTypes): array
    {
        $roles = PermissionCatalog::roles();

        return array_values(array_filter(
            PermissionCatalog::rolesWithPermission(
                PermissionCatalog::PERMISSION_DEPARTMENT_SHIFT_ADDITIONS_OVERRIDE,
            ),
            fn (string $roleCode): bool => in_array(
                $roles[$roleCode]['scope_type'] ?? '',
                $scopeTypes,
                true,
            ),
        ));
    }
}
