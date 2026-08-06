<?php

declare(strict_types=1);

namespace App\Services\Departments;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may read a department's staff list, and how much of each person they see
 * (M18.30; UI contract 12.4 `department.roster`; VOL-011, VOL-012).
 *
 * Three standings reach the roster and they reach different amounts of it.
 *
 *  - **`department.administer`** — department lead and department
 *    administration. The whole department, with emergency contacts.
 *  - **`department.schedule.manage`** — department planning. The whole
 *    department, without them.
 *  - **A designated `shift_lead`** — the contract's "permitted lead". Only the
 *    teams they lead, without them. This is the same narrowing
 *    {@see DepartmentSelfAdminAccess::ledTeamIds()} applies on the Admin page,
 *    so one team's roster never renders under another team's authority.
 *
 * Planning being here is deliberate and is not a hole in SLB-019. That
 * requirement makes the *Planning Table* identity-free, and SLB-020 says so in
 * as many words — filters "may narrow the view without changing authorization
 * or revealing identities on the Planning Table" — which only means anything if
 * the same authorization reaches surfaces where identities do appear. The
 * Planning Table stays aggregate-only; this is a different screen, and somebody
 * building next week's coverage has to know who is available to build it from.
 *
 * Emergency contacts follow VOL-012 rather than the page: department leads have
 * them "for staff in their department", and VOL-011 gives organizers no default
 * access to them at all. That is the same population M13.3 resolved for the
 * staff contact export — the holders of `department.administer` over this
 * department — so the roster and the file cannot disagree about who may read a
 * next-of-kin phone number. An organizer standing in their own Organizers
 * Department holds neither capability here and reaches no roster through this
 * surface, which is what keeps VOL-011 true without a second rule.
 *
 * Every question is asked against the event, so a grant scoped to a different
 * event does not answer for this one.
 */
final class DepartmentRosterAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * The caller's roster standing in this department, or null when they hold
     * none. Null is a 403 rather than an empty list: "this department has no
     * staff" and "you do not read this department's staff" are different facts.
     */
    public function resolve(User $user, Event $event, Department $department): ?DepartmentRosterScope
    {
        $canAdminister = false;
        $canPlan = false;
        $ledTeamIds = [];

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                $team = Team::query()->find($role->teamId);

                if ($team === null || (string) $team->department_id !== (string) $department->getKey()) {
                    continue;
                }

                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_DEPARTMENT_ADMINISTER,
                )) {
                    $canAdminister = true;
                }

                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_DEPARTMENT_SCHEDULE_MANAGE,
                )) {
                    $canPlan = true;
                }

                if ($role->roleCode === PermissionCatalog::ROLE_SHIFT_LEAD) {
                    $ledTeamIds[] = (string) $team->getKey();
                }
            }
        }

        $wholeDepartment = $canAdminister || $canPlan;
        $ledTeamIds = array_values(array_unique($ledTeamIds));

        if (! $wholeDepartment && $ledTeamIds === []) {
            return null;
        }

        return new DepartmentRosterScope(
            wholeDepartment: $wholeDepartment,
            teamIds: $wholeDepartment ? [] : $ledTeamIds,
            emergencyContacts: $canAdminister,
        );
    }
}
