<?php

namespace App\Services\Permissions;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\PermissionRole;
use App\Models\Team;
use App\Models\TeamGrant;
use InvalidArgumentException;

/**
 * Creates and revokes team grants, the only supported path for granting system
 * authority (TEAM-009, TEAM-010). Free-floating user-level grants are not
 * provided here; god-mode/admin repair via direct user roles is out of scope.
 */
class TeamGrantService
{
    /**
     * Grant a permission role to every member of a team.
     */
    public function grant(Team $team, PermissionRole $role, ?Event $event = null): TeamGrant
    {
        $this->assertGrantIsAllowed($team, $role, $event);

        return TeamGrant::query()->create([
            'team_id' => $team->id,
            'event_id' => $event?->id,
            'permission_role_id' => $role->id,
        ]);
    }

    /**
     * Revoke an active team grant while preserving the historical record.
     */
    public function revoke(TeamGrant $grant): TeamGrant
    {
        if (! $grant->isRevoked()) {
            $grant->forceFill(['revoked_at' => now()])->save();
        }

        return $grant;
    }

    private function assertGrantIsAllowed(Team $team, PermissionRole $role, ?Event $event): void
    {
        if ($role->scope_type === PermissionRole::SCOPE_NODE) {
            throw new InvalidArgumentException('Node-scoped roles such as god_mode cannot be granted through teams.');
        }

        if ($role->scope_type === PermissionRole::SCOPE_EVENT && $event === null) {
            throw new InvalidArgumentException('Event-scoped roles require an event.');
        }

        if (in_array($role->code, [PermissionCatalog::ROLE_ORGANIZER, PermissionCatalog::ROLE_LEAD_ORGANIZER], true)) {
            $this->assertTeamIsInOrganizersDepartment($team);
        }
    }

    private function assertTeamIsInOrganizersDepartment(Team $team): void
    {
        $department = $team->department()->with('organization')->first();
        $organization = $department?->organization;

        if ($organization === null || $organization->organizers_department_id === null) {
            throw new InvalidArgumentException('Organizer roles require the organization to have a configured Organizers Department.');
        }

        if ((int) $department->id !== (int) $organization->organizers_department_id) {
            throw new InvalidArgumentException('Organizer roles can only be granted to teams in the configured Organizers Department.');
        }
    }
}
