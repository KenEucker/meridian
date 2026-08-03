<?php

namespace App\Services\Attendance;

use App\Models\Shift;
use App\Models\User;
use App\Services\Permissions\DepartmentOperationalAccess;

/**
 * Authorization for staff-mediated attendance operations (SLB-003, SLB-004;
 * technical spec section 20.2).
 *
 * The four operations — check-in, check-out, mark-no-show, and hours
 * correction — are the work of one population, the authorized attendance
 * managers TEAM-015 names: `department_logistics` holders together with
 * department leads and shift leads for the department (SLB-007, SLB-029;
 * HOURS-007). Four public answers, one resolution, so the operations cannot
 * drift apart about who is authorized.
 */
class AttendanceCheckInAccess
{
    public function __construct(private readonly DepartmentOperationalAccess $departmentAccess) {}

    public function canCheckInForShift(User $user, Shift $shift): bool
    {
        return $this->canManageAttendanceForShift($user, $shift);
    }

    public function canCheckOutForShift(User $user, Shift $shift): bool
    {
        return $this->canManageAttendanceForShift($user, $shift);
    }

    public function canMarkNoShowForShift(User $user, Shift $shift): bool
    {
        return $this->canManageAttendanceForShift($user, $shift);
    }

    public function canCorrectHoursForShift(User $user, Shift $shift): bool
    {
        return $this->canManageAttendanceForShift($user, $shift);
    }

    private function canManageAttendanceForShift(User $user, Shift $shift): bool
    {
        $shift->loadMissing(['event', 'department']);

        if ($shift->event === null || $shift->department === null || $shift->department->isArchived()) {
            return false;
        }

        return $this->departmentAccess->canManageAttendance($user, $shift->event, $shift->department);
    }
}
