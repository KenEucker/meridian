<?php

namespace App\Services\Teams;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Team;
use App\Models\TeamDesignation;
use App\Models\TeamGrant;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Permissions\TeamGrantService;
use Illuminate\Support\Facades\DB;

/**
 * Team designations (M18.10; TEAM-011 through TEAM-014, TEAM-017).
 *
 * A designation attaches an existing operational grant to a named team: it
 * creates and owns a `team_grants` row rather than becoming a second authority
 * path, so every existing permission check, the effective role resolver, and
 * PowerSync replication keep reading the one source they already read
 * (TEAM-010). A department designates zero or one team per section 4.8A
 * function, the same team may hold more than one designation (TEAM-012), and
 * an organization designates the Staff Coordinator team within its configured
 * Organizers Department (TEAM-014).
 *
 * Removing a designation revokes the grant the designation created and only
 * that grant: a department whose structure needs a second grant-bearing team
 * attaches that grant directly, and designation changes never touch it
 * (TEAM-013). Creation, change, and removal are audited with the department or
 * organization, the function, and the team recorded (TEAM-017).
 */
final class TeamDesignationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamGrantService $teamGrants,
    ) {}

    /**
     * Designate the team carrying a department operational function,
     * replacing the function's current designation when one exists.
     *
     * @throws TeamDesignationException when the designation is not valid
     */
    public function designateDepartmentTeam(
        Department $department,
        string $functionCode,
        Team $team,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamDesignation {
        if (! in_array($functionCode, TeamDesignation::departmentFunctions(), true)) {
            throw new TeamDesignationException('Unknown department function for team designation.');
        }

        return DB::transaction(function () use ($department, $functionCode, $team, $actor, $sourceContext): TeamDesignation {
            $team = Team::query()
                ->with('department.organization')
                ->whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $team->department_id !== (string) $department->id) {
                throw new TeamDesignationException('A department can only designate its own teams.');
            }

            $this->assertTeamEligible($team);

            $current = TeamDesignation::query()
                ->active()
                ->where('department_id', $department->id)
                ->where('function_code', $functionCode)
                ->lockForUpdate()
                ->first();

            return $this->replaceDesignation(
                current: $current,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                functionCode: $functionCode,
                team: $team,
                actor: $actor,
                sourceContext: $sourceContext,
            );
        });
    }

    /**
     * Remove a department function's designation, revoking the grant the
     * designation created.
     *
     * @throws TeamDesignationException when the function is unknown or not designated
     */
    public function removeDepartmentTeam(
        Department $department,
        string $functionCode,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamDesignation {
        if (! in_array($functionCode, TeamDesignation::departmentFunctions(), true)) {
            throw new TeamDesignationException('Unknown department function for team designation.');
        }

        return DB::transaction(function () use ($department, $functionCode, $actor, $sourceContext): TeamDesignation {
            $current = TeamDesignation::query()
                ->active()
                ->where('department_id', $department->id)
                ->where('function_code', $functionCode)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new TeamDesignationException('This function has no designated team to remove.');
            }

            return $this->removeDesignation($current, $actor, $sourceContext);
        });
    }

    /**
     * Designate the organization's Staff Coordinator team within its
     * configured Organizers Department (TEAM-014), replacing the current
     * designation when one exists.
     *
     * @throws TeamDesignationException when the designation is not valid
     */
    public function designateStaffCoordinatorTeam(
        Organization $organization,
        Team $team,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamDesignation {
        return DB::transaction(function () use ($organization, $team, $actor, $sourceContext): TeamDesignation {
            $team = Team::query()
                ->with('department.organization')
                ->whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($organization->organizers_department_id === null) {
                throw new TeamDesignationException('Staff Coordinator designation requires the organization to have a configured Organizers Department.');
            }

            if ((string) $team->department_id !== (string) $organization->organizers_department_id) {
                throw new TeamDesignationException('The Staff Coordinator team must belong to the configured Organizers Department.');
            }

            $this->assertTeamEligible($team);

            $current = TeamDesignation::query()
                ->active()
                ->where('organization_id', $organization->id)
                ->whereNull('department_id')
                ->where('function_code', TeamDesignation::FUNCTION_STAFF_COORDINATOR)
                ->lockForUpdate()
                ->first();

            return $this->replaceDesignation(
                current: $current,
                organizationId: (string) $organization->id,
                departmentId: null,
                functionCode: TeamDesignation::FUNCTION_STAFF_COORDINATOR,
                team: $team,
                actor: $actor,
                sourceContext: $sourceContext,
            );
        });
    }

    /**
     * Remove the organization's Staff Coordinator designation, revoking the
     * grant the designation created.
     *
     * @throws TeamDesignationException when no Staff Coordinator team is designated
     */
    public function removeStaffCoordinatorTeam(
        Organization $organization,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamDesignation {
        return DB::transaction(function () use ($organization, $actor, $sourceContext): TeamDesignation {
            $current = TeamDesignation::query()
                ->active()
                ->where('organization_id', $organization->id)
                ->whereNull('department_id')
                ->where('function_code', TeamDesignation::FUNCTION_STAFF_COORDINATOR)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new TeamDesignationException('This organization has no designated Staff Coordinator team to remove.');
            }

            return $this->removeDesignation($current, $actor, $sourceContext);
        });
    }

    private function replaceDesignation(
        ?TeamDesignation $current,
        string $organizationId,
        ?string $departmentId,
        string $functionCode,
        Team $team,
        User $actor,
        string $sourceContext,
    ): TeamDesignation {
        if ($current !== null && (string) $current->team_id === (string) $team->id) {
            throw new TeamDesignationException('This team already carries the designation.');
        }

        $before = $current !== null ? $this->snapshot($current) : null;

        if ($current !== null) {
            $this->revokeOwnedGrant($current);
            $current->forceFill(['removed_at' => now()])->save();
        }

        $grant = $this->teamGrants->grant($team, $this->roleFor($functionCode));

        $designation = TeamDesignation::query()->create([
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'function_code' => $functionCode,
            'team_id' => $team->id,
            'team_grant_id' => $grant->id,
        ]);

        $this->audit->recordForEntity(
            entity: $designation,
            action: $current === null ? 'team_designation.created' : 'team_designation.changed',
            actorUser: $actor,
            organizationId: $organizationId,
            departmentId: $departmentId,
            before: $before,
            after: $this->snapshot($designation->load('team')),
            reason: 'Designated '.$team->name.' as the '.$this->functionLabel($functionCode).' team.',
            sourceContext: $sourceContext,
        );

        return $designation;
    }

    private function removeDesignation(
        TeamDesignation $current,
        User $actor,
        string $sourceContext,
    ): TeamDesignation {
        $before = $this->snapshot($current);

        $this->revokeOwnedGrant($current);
        $current->forceFill(['removed_at' => now()])->save();
        $current->refresh();

        $this->audit->recordForEntity(
            entity: $current,
            action: 'team_designation.removed',
            actorUser: $actor,
            organizationId: (string) $current->organization_id,
            departmentId: $current->department_id !== null ? (string) $current->department_id : null,
            before: $before,
            after: $this->snapshot($current),
            reason: 'Removed '.($current->team?->name ?? 'the designated team').' as the '.$this->functionLabel($current->function_code).' team.',
            sourceContext: $sourceContext,
        );

        return $current;
    }

    private function revokeOwnedGrant(TeamDesignation $designation): void
    {
        $grant = TeamGrant::query()->find($designation->team_grant_id);

        if ($grant !== null) {
            $this->teamGrants->revoke($grant);
        }
    }

    private function roleFor(string $functionCode): PermissionRole
    {
        $roleCode = TeamDesignation::functionRoleCodes()[$functionCode];

        return PermissionRole::query()->where('code', $roleCode)->firstOrFail();
    }

    /**
     * @throws TeamDesignationException
     */
    private function assertTeamEligible(Team $team): void
    {
        if ($team->isArchived()) {
            throw new TeamDesignationException('Archived teams cannot be designated.');
        }

        $department = $team->department;

        if ($department === null || $department->isArchived()) {
            throw new TeamDesignationException('Teams of archived departments cannot be designated.');
        }
    }

    private function functionLabel(string $functionCode): string
    {
        return str_replace('_', ' ', ucwords($functionCode, '_'));
    }

    /**
     * The audit snapshot records the department or organization, the function,
     * and the team the designation attaches (TEAM-017).
     *
     * @return array<string, mixed>
     */
    private function snapshot(TeamDesignation $designation): array
    {
        $designation->loadMissing('team');

        return [
            'organization_id' => (string) $designation->organization_id,
            'department_id' => $designation->department_id !== null ? (string) $designation->department_id : null,
            'function_code' => $designation->function_code,
            'team_id' => (string) $designation->team_id,
            'team_name' => $designation->team?->name,
            'team_grant_id' => (string) $designation->team_grant_id,
            'removed_at' => $designation->removed_at?->toISOString(),
        ];
    }
}
