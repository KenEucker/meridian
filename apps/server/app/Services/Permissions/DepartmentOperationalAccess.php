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

    public function canManageAttendance(User $user, Event $event, Department $department): bool
    {
        return $this->hasDepartmentRole($user, $event, $department, [
            PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS,
        ]);
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
                    ->whereHas('departmentMembership', fn (Builder $departmentMembershipQuery) => $departmentMembershipQuery
                        ->active()
                        ->where('status', DepartmentMembership::STATUS_ACTIVE))))
            ->exists();
    }
}
