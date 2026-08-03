<?php

namespace App\Services\Permissions;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
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

        if ($event !== null && $this->isIncidentCommandRole($role)) {
            $this->assertTeamIsInEventIncidentCommandDepartment($team, $event);
        }

        // Staff Coordinator authority lives on a team within the configured
        // Organizers Department (TEAM-014), so it shares the organizer roles'
        // department restriction.
        if (in_array($role->code, [
            PermissionCatalog::ROLE_ORGANIZER,
            PermissionCatalog::ROLE_LEAD_ORGANIZER,
            PermissionCatalog::ROLE_STAFF_COORDINATOR,
        ], true)) {
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

        if ((string) $department->id !== (string) $organization->organizers_department_id) {
            throw new InvalidArgumentException('Organizer roles can only be granted to teams in the configured Organizers Department.');
        }
    }

    private function assertTeamIsInEventIncidentCommandDepartment(Team $team, Event $event): void
    {
        $department = $team->department()->first();
        $icDepartment = $this->effectiveIncidentCommandDepartment($event);

        if ($icDepartment === null) {
            throw new InvalidArgumentException('IC roles require the event to have an Incident Command department.');
        }

        if ($department === null || (string) $department->id !== (string) $icDepartment->id) {
            throw new InvalidArgumentException('IC roles can only be granted to teams in the event Incident Command department.');
        }
    }

    private function effectiveIncidentCommandDepartment(Event $event): ?Department
    {
        if ($event->ic_department_id !== null) {
            return Department::query()->find($event->ic_department_id);
        }

        $event->loadMissing(['icDepartment', 'organization.defaultIcDepartment']);

        return $event->organization?->defaultIcDepartment;
    }

    private function isIncidentCommandRole(PermissionRole $role): bool
    {
        return in_array($role->code, [
            PermissionCatalog::ROLE_IC_LEAD,
            PermissionCatalog::ROLE_IC_OPERATOR,
            PermissionCatalog::ROLE_IC_VIEWER,
        ], true);
    }
}
