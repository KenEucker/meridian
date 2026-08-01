<?php

namespace App\Services\DepartmentOps;

/**
 * What one caller may do on the department operations surfaces (SLB-001 through
 * SLB-022; technical spec 20.2, 20.5).
 *
 * Every flag is the same answer the matching command enforces, resolved once so
 * a screen offers only what the node would accept and the node decides again
 * regardless (CLIENT-006).
 */
final class DepartmentOperationsAuthority
{
    public function __construct(
        public readonly bool $isDepartmentLead,
        public readonly bool $canManagePresence,
        public readonly bool $canManageAttendance,
        public readonly bool $canManageEquipment,
        public readonly bool $canAssignDeployments,
        public readonly bool $canManagePlanning,
        public readonly bool $canAdministerDepartment,
    ) {}

    /**
     * Whether this caller has any standing in the department at all.
     *
     * The gate on the reads. Someone with none of these is not refused a
     * particular action, they are not a member of this department's operations,
     * and the surface has nothing to show them.
     */
    public function canViewOperations(): bool
    {
        return $this->isDepartmentLead
            || $this->canManagePresence
            || $this->canManageAttendance
            || $this->canManageEquipment
            || $this->canAssignDeployments
            || $this->canManagePlanning
            || $this->canAdministerDepartment;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'is_department_lead' => $this->isDepartmentLead,
            'can_manage_presence' => $this->canManagePresence,
            'can_manage_attendance' => $this->canManageAttendance,
            'can_manage_equipment' => $this->canManageEquipment,
            'can_assign_deployments' => $this->canAssignDeployments,
            'can_manage_planning' => $this->canManagePlanning,
            'can_administer_department' => $this->canAdministerDepartment,
        ];
    }
}
