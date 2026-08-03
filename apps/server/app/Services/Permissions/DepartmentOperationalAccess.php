<?php

namespace App\Services\Permissions;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\TeamGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DepartmentOperationalAccess
{
    public function canManagePresence(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
        ]);
    }

    /**
     * Whether the user is an authorized attendance manager for the department
     * (TEAM-015): a `department_logistics` holder, a department lead, or a
     * shift lead for the department. Check-in, check-out, mark-no-show, and
     * hours correction all resolve here (SLB-007, SLB-029; HOURS-007), so the
     * four operations cannot disagree about who is authorized.
     *
     * Shift lead authority follows the role's own scoping (M11.17): the grant
     * answers only for a membership designated `lead` in the grant-bearing
     * team, so ordinary members of a team that carries a shift_lead grant are
     * not attendance managers.
     */
    public function canManageAttendance(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
            PermissionCatalog::ROLE_DEPARTMENT_LEAD,
        ]) || $this->isDesignatedShiftLead($user, $event, $department);
    }

    public function canManageEquipment(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
        ]);
    }

    public function canAssignDeployments(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS,
        ]);
    }

    public function canManagePlanning(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_PLANNING,
        ]);
    }

    public function canManageDepartmentAdministration(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_ADMINISTRATION,
        ]);
    }

    /**
     * @param  list<string>  $roleCodes
     */
    public function hasDepartmentRole(
        User $user,
        Event $event,
        Department $department,
        array $roleCodes,
    ): bool {
        return $this->hasDepartmentGrant($user, $event, $department, $roleCodes, leadMembershipOnly: false);
    }

    /**
     * Whether the user is a designated shift lead of any team in the
     * department: a team-scoped `shift_lead` grant reached through a
     * membership designated `lead` (M11.17; technical spec 15.2).
     */
    private function isDesignatedShiftLead(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentGrant($user, $event, $department, [
            PermissionCatalog::ROLE_SHIFT_LEAD,
        ], leadMembershipOnly: true);
    }

    /**
     * @param  list<string>  $roleCodes
     */
    private function hasDepartmentGrant(
        User $user,
        Event $event,
        Department $department,
        array $roleCodes,
        bool $leadMembershipOnly,
    ): bool {
        if ($roleCodes === []) {
            return false;
        }

        $staffIds = $user->staffProfiles()
            ->get(['staff.id'])
            ->pluck('id');

        if ($staffIds->isEmpty()) {
            return false;
        }

        return TeamGrant::query()
            ->active()
            ->where(function (Builder $eventQuery) use ($event): void {
                $eventQuery->whereNull('event_id')
                    ->orWhere('event_id', $event->id);
            })
            ->whereHas('permissionRole', fn (Builder $roleQuery) => $roleQuery
                ->whereIn('code', $roleCodes))
            ->whereHas('team', fn (Builder $teamQuery) => $teamQuery
                ->active()
                ->where('department_id', $department->id)
                ->whereHas('memberships', fn (Builder $membershipQuery) => $membershipQuery
                    ->active()
                    ->whereIn('staff_id', $staffIds)
                    ->when($leadMembershipOnly, fn (Builder $leadQuery) => $leadQuery
                        ->where('membership_role', 'lead'))
                    ->whereHas('departmentMembership', fn (Builder $departmentMembershipQuery) => $departmentMembershipQuery
                        ->active()
                        ->where('status', DepartmentMembership::STATUS_ACTIVE))))
            ->exists();
    }
}
