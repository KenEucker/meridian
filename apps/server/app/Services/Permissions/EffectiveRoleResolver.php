<?php

namespace App\Services\Permissions;

use App\Models\Event;
use App\Models\Staff;
use App\Models\TeamGrant;
use Illuminate\Support\Collection;

/**
 * Resolves the effective roles a staff member holds through their active team
 * memberships and active team grants (TEAM-009). Grants that are revoked, or
 * event-scoped to a different event, are excluded.
 */
class EffectiveRoleResolver
{
    /**
     * @return Collection<int, EffectiveRole>
     */
    public function resolveForStaff(Staff $staff, ?Event $event = null): Collection
    {
        $teamIds = $staff->teamMemberships()
            ->active()
            ->pluck('team_id')
            ->unique()
            ->values();

        if ($teamIds->isEmpty()) {
            return collect();
        }

        return TeamGrant::query()
            ->active()
            ->whereIn('team_id', $teamIds)
            ->where(function ($query) use ($event): void {
                $query->whereNull('event_id');

                if ($event !== null) {
                    $query->orWhere('event_id', $event->id);
                }
            })
            ->with(['permissionRole', 'team'])
            ->get()
            ->map(function (TeamGrant $grant): EffectiveRole {
                $roleName = $grant->permissionRole->name;
                $teamName = $grant->team->name;

                return new EffectiveRole(
                    roleCode: $grant->permissionRole->code,
                    roleName: $roleName,
                    teamId: (int) $grant->team_id,
                    teamName: $teamName,
                    teamGrantId: (int) $grant->id,
                    eventId: $grant->event_id,
                    reason: "You have the {$roleName} role because you are a member of the {$teamName} team.",
                );
            })
            ->values();
    }
}
