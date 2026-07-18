<?php

namespace App\Services\Attendance;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Shift;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Authorization for staff-mediated attendance check-in (SLB-003; technical
 * spec section 20.2). Shift leads are scoped to the shift eligible team;
 * department leads are scoped to their departments.
 */
class AttendanceCheckInAccess
{
    public function __construct(private readonly ApplicationReviewAccess $applicationReviewAccess) {}

    public function canCheckInForShift(User $user, Shift $shift): bool
    {
        $shift->loadMissing('department');

        if ($shift->department === null || $shift->department->isArchived()) {
            return false;
        }

        if ($this->isShiftLeadForShiftTeam($user, $shift)) {
            return true;
        }

        return $this->applicationReviewAccess
            ->departmentLeadDepartmentIds($user)
            ->contains((string) $shift->department_id);
    }

    private function isShiftLeadForShiftTeam(User $user, Shift $shift): bool
    {
        $staffIds = $user->staffProfiles()
            ->get(['staff.id'])
            ->pluck('id');

        if ($staffIds->isEmpty()) {
            return false;
        }

        return TeamMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->where('team_id', $shift->eligible_team_id)
            ->whereHas('team', fn (Builder $teamQuery) => $teamQuery->active())
            ->whereHas('team.grants', function (Builder $grantQuery) use ($shift): void {
                $grantQuery
                    ->active()
                    ->where(function (Builder $eventQuery) use ($shift): void {
                        $eventQuery->whereNull('event_id')
                            ->orWhere('event_id', $shift->event_id);
                    })
                    ->whereHas('permissionRole', fn (Builder $roleQuery) => $roleQuery
                        ->where('code', PermissionCatalog::ROLE_SHIFT_LEAD));
            })
            ->exists();
    }
}
