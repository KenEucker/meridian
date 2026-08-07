<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Staff;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the {@see EventHorizonViewer} for one request (technical spec
 * 21D.5).
 *
 * Nothing new is decided here. Staff profiles, active department memberships,
 * and team memberships are resolved exactly the way the shift board and the
 * staff dashboard resolve them, because the Event Horizon is a second reader
 * of those domains and must answer "who is this" the same way the surfaces
 * behind its action links do.
 */
final class EventHorizonViewerResolver
{
    public function resolve(User $user, Event $event): EventHorizonViewer
    {
        $staffIds = $user->staffProfiles()
            ->pluck('staff.id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        /*
         * Only profiles standing in the event's organization: holding a status
         * with it, or an active membership of one of its departments. A God
         * Mode operator or an organizer of another organization has staff
         * profiles and none of them are staff *here*.
         */
        $staffIds = array_values(array_filter(
            $staffIds,
            function (string $staffId) use ($event): bool {
                $staff = Staff::query()->find($staffId);

                if ($staff === null) {
                    return false;
                }

                $holdsStatus = $staff->organizationStatuses()
                    ->where('organization_id', $event->organization_id)
                    ->exists();

                if ($holdsStatus) {
                    return true;
                }

                return $staff->departmentMemberships()
                    ->active()
                    ->where('status', DepartmentMembership::STATUS_ACTIVE)
                    ->whereHas('department', fn (Builder $query) => $query
                        ->where('organization_id', $event->organization_id))
                    ->exists();
            },
        ));

        if ($staffIds === []) {
            return new EventHorizonViewer($user, [], [], [], []);
        }

        /*
         * Keyed by department because that is the grain requirements are asked
         * at: a login holding two staff records may belong to one department
         * through one of them and another department through the other, the
         * same resolution the shift board documents at length.
         */
        $memberships = DepartmentMembership::query()
            ->active()
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->whereIn('staff_id', $staffIds)
            ->whereHas('department', fn (Builder $query) => $query
                ->where('organization_id', $event->organization_id))
            ->get();

        $staffIdByDepartmentId = [];

        foreach ($memberships as $membership) {
            $staffIdByDepartmentId[(string) $membership->department_id] = (string) $membership->staff_id;
        }

        $teamMemberships = $memberships->isEmpty()
            ? collect()
            : TeamMembership::query()
                ->onEligibleShiftTeam($memberships->map(
                    fn (DepartmentMembership $membership): string => (string) $membership->getKey(),
                )->all())
                ->get(['team_id', 'membership_role']);

        return new EventHorizonViewer(
            user: $user,
            staffIds: $staffIds,
            staffIdByDepartmentId: $staffIdByDepartmentId,
            teamIds: $teamMemberships
                ->pluck('team_id')
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all(),
            ledTeamIds: $teamMemberships
                ->where('membership_role', 'lead')
                ->pluck('team_id')
                ->map(fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all(),
        );
    }
}
