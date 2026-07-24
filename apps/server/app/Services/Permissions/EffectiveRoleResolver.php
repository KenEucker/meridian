<?php

namespace App\Services\Permissions;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\Staff;
use App\Models\TeamGrant;
use Illuminate\Support\Collection;

/**
 * Resolves the effective roles a staff member holds through their active team
 * memberships and active team grants (TEAM-009). Grants that are revoked, or
 * event-scoped to a different event, are excluded.
 *
 * The team-scoped `shift_lead` role applies only to memberships designated
 * `membership_role = 'lead'` in the grant-bearing team, so department leads can
 * designate individual team leads without every team member gaining lead
 * authority (M11.17; technical spec 15.2).
 */
class EffectiveRoleResolver
{
    /**
     * @return Collection<int, EffectiveRole>
     */
    public function resolveForStaff(Staff $staff, ?Event $event = null): Collection
    {
        $memberships = $staff->teamMemberships()
            ->active()
            ->get(['team_id', 'membership_role']);

        $teamIds = $memberships->pluck('team_id')->unique()->values();
        $leadTeamIds = $memberships
            ->where('membership_role', 'lead')
            ->pluck('team_id')
            ->unique()
            ->values();

        if ($teamIds->isEmpty()) {
            return collect();
        }

        $icDepartmentId = $event === null ? null : $this->effectiveIncidentCommandDepartmentId($event);

        return TeamGrant::query()
            ->active()
            ->whereIn('team_id', $teamIds)
            ->where(function ($query) use ($event): void {
                $query->whereNull('event_id');

                if ($event !== null) {
                    $query->orWhere('event_id', $event->id);
                }
            })
            ->with(['permissionRole', 'team.department'])
            ->get()
            ->filter(function (TeamGrant $grant) use ($icDepartmentId): bool {
                if (! $this->isIncidentCommandGrant($grant)) {
                    return true;
                }

                return $icDepartmentId !== null
                    && (string) $grant->team->department_id === (string) $icDepartmentId;
            })
            ->filter(function (TeamGrant $grant) use ($leadTeamIds): bool {
                if ($grant->permissionRole->code !== PermissionCatalog::ROLE_SHIFT_LEAD) {
                    return true;
                }

                return $leadTeamIds->contains((string) $grant->team_id);
            })
            ->map(function (TeamGrant $grant): EffectiveRole {
                $roleName = $grant->permissionRole->name;
                $teamName = $grant->team->name;
                $reason = $grant->permissionRole->code === PermissionCatalog::ROLE_SHIFT_LEAD
                    ? "You have the {$roleName} role because you are a designated lead of the {$teamName} team."
                    : "You have the {$roleName} role because you are a member of the {$teamName} team.";

                return new EffectiveRole(
                    roleCode: $grant->permissionRole->code,
                    roleName: $roleName,
                    teamId: (string) $grant->team_id,
                    teamName: $teamName,
                    teamGrantId: (string) $grant->id,
                    eventId: $grant->event_id,
                    reason: $reason,
                );
            })
            ->values();
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing(['icDepartment', 'organization.defaultIcDepartment']);

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }

    private function isIncidentCommandGrant(TeamGrant $grant): bool
    {
        return in_array($grant->permissionRole->code, [
            PermissionCatalog::ROLE_IC_LEAD,
            PermissionCatalog::ROLE_IC_OPERATOR,
            PermissionCatalog::ROLE_IC_VIEWER,
        ], true);
    }
}
