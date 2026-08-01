<?php

namespace App\Services\DepartmentOps;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\DepartmentOperationalAccess;

/**
 * Resolves one caller's standing across the department operations surfaces.
 *
 * The individual answers already exist on {@see DepartmentOperationalAccess} and
 * are what the presence, attendance, equipment, and deployment commands enforce.
 * This resolves all of them in one place so a read can publish them together and
 * a screen never has to guess which of its controls would be refused.
 *
 * Department lead is carried separately because it is a designation rather than
 * a capability: technical spec 20.2 gives leads broader attendance visibility
 * for their department, and SLB-001 makes the Overview theirs.
 */
class DepartmentOperationsAccess
{
    public function __construct(private readonly DepartmentOperationalAccess $access) {}

    public function resolve(User $user, Event $event, Department $department): DepartmentOperationsAuthority
    {
        return new DepartmentOperationsAuthority(
            isDepartmentLead: $this->access->hasDepartmentRole($user, $event, $department, [
                PermissionCatalog::ROLE_DEPARTMENT_LEAD,
            ]),
            canManagePresence: $this->access->canManagePresence($user, $event, $department),
            canManageAttendance: $this->access->canManageAttendance($user, $event, $department),
            canManageEquipment: $this->access->canManageEquipment($user, $event, $department),
            canAssignDeployments: $this->access->canAssignDeployments($user, $event, $department),
            canManagePlanning: $this->access->canManagePlanning($user, $event, $department),
            canAdministerDepartment: $this->access->canManageDepartmentAdministration($user, $event, $department),
        );
    }
}
