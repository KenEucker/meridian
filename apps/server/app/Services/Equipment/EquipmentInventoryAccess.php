<?php

namespace App\Services\Equipment;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Product-path equipment inventory setup authorization (M11.18; UI contract
 * 12.4 `department.equipment`: "Department logistics/administration as
 * permitted").
 *
 * Inventory setup happens before operations, so it is department-scoped and
 * event-independent: it is authorized by `department.equipment.manage`
 * (`department_logistics`, the role EQUIP-004 puts in charge of equipment) or
 * by `department.administer` (`department_lead`, `department_administration`).
 * The event-scoped {@see \App\Services\Permissions\DepartmentOperationalAccess}
 * gate stays the authority for the Logistics checkout/check-in workflow this
 * inventory feeds.
 *
 * Authority never crosses departments. Department-to-department allotments are
 * excluded from MVP by EQUIP-006 and are deliberately not modeled here.
 */
final class EquipmentInventoryAccess
{
    /**
     * Capabilities that may create and maintain department equipment inventory.
     *
     * @var list<string>
     */
    private const INVENTORY_PERMISSIONS = [
        PermissionCatalog::PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE,
        PermissionCatalog::PERMISSION_DEPARTMENT_ADMINISTER,
    ];

    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageInventory(User $user, Department $department): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                $grantsInventory = false;

                foreach (self::INVENTORY_PERMISSIONS as $permission) {
                    if (PermissionCatalog::roleHasPermission($role->roleCode, $permission)) {
                        $grantsInventory = true;

                        break;
                    }
                }

                if (! $grantsInventory) {
                    continue;
                }

                $team = Team::query()->find($role->teamId);

                if ($team === null) {
                    continue;
                }

                if ((string) $team->department_id === (string) $department->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
