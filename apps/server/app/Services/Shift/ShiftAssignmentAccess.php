<?php

namespace App\Services\Shift;

use App\Models\Shift;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;

/**
 * Authorization for lead-driven shift assignment (SHIFT-015).
 *
 * Department leads may assign staff to shifts in departments they lead.
 */
class ShiftAssignmentAccess
{
    public function canAssignToShift(User $user, Shift $shift): bool
    {
        $shift->loadMissing('department');

        if ($shift->department === null || $shift->department->isArchived()) {
            return false;
        }

        return app(ApplicationReviewAccess::class)
            ->departmentLeadDepartmentIds($user)
            ->contains((string) $shift->department_id);
    }
}
