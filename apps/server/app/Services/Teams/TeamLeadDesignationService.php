<?php

namespace App\Services\Teams;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Permissions\TeamGrantService;
use Illuminate\Support\Facades\DB;

/**
 * Product-path team lead designation for department leads (M11.17).
 *
 * A team lead is an active team member designated `membership_role = 'lead'`
 * on a team holding an active team-scoped `shift_lead` grant (TEAM-009;
 * technical spec 15.2). Selecting a lead designates the membership and ensures
 * the grant; removing a lead returns the membership to `member` while
 * preserving team membership and history.
 */
final class TeamLeadDesignationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamGrantService $teamGrants,
    ) {}

    /**
     * @throws TeamAdminException when designation is not permitted or valid
     */
    public function selectTeamLead(
        Staff $staff,
        Team $team,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamMembership {
        return DB::transaction(function () use ($staff, $team, $actor, $sourceContext): TeamMembership {
            $team = Team::query()
                ->with('department')
                ->whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTeamEligible($team);

            $departmentMembership = DepartmentMembership::query()
                ->active()
                ->where('department_id', $team->department_id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($departmentMembership === null) {
                throw new TeamAdminException('Staff must belong to the department before team lead designation.');
            }

            $this->assertStaffMayBeStaffed($team, $staff);

            $membership = TeamMembership::query()
                ->where('team_id', $team->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            $before = $membership !== null ? $this->snapshot($membership) : null;

            if ($membership === null) {
                $membership = $departmentMembership->teamMemberships()->create([
                    'team_id' => $team->id,
                    'staff_id' => $staff->id,
                    'membership_role' => 'lead',
                ]);
            } else {
                if ($membership->archived_at === null && $membership->membership_role === 'lead') {
                    throw new TeamAdminException('This staff member is already a lead of the selected team.');
                }

                $membership->forceFill([
                    'department_membership_id' => $departmentMembership->id,
                    'membership_role' => 'lead',
                    'archived_at' => null,
                ])->save();
                $membership->refresh();
            }

            $this->ensureShiftLeadGrant($team);

            $this->audit->recordForEntity(
                entity: $membership,
                action: 'team_lead.selected',
                actorUser: $actor,
                organizationId: $team->department?->organization_id,
                departmentId: (string) $team->department_id,
                before: $before,
                after: $this->snapshot($membership),
                reason: 'Designated as team lead of '.$team->name.'.',
                sourceContext: $sourceContext,
            );

            return $membership->load('team');
        });
    }

    /**
     * @throws TeamAdminException when the staff member is not a lead of the team
     */
    public function removeTeamLead(
        Staff $staff,
        Team $team,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamMembership {
        return DB::transaction(function () use ($staff, $team, $actor, $sourceContext): TeamMembership {
            $team = Team::query()
                ->with('department')
                ->whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail();

            $membership = TeamMembership::query()
                ->active()
                ->where('team_id', $team->id)
                ->where('staff_id', $staff->id)
                ->where('membership_role', 'lead')
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw new TeamAdminException('This staff member is not a lead of the selected team.');
            }

            $before = $this->snapshot($membership);
            $membership->forceFill(['membership_role' => 'member'])->save();
            $membership->refresh();

            $this->audit->recordForEntity(
                entity: $membership,
                action: 'team_lead.removed',
                actorUser: $actor,
                organizationId: $team->department?->organization_id,
                departmentId: (string) $team->department_id,
                before: $before,
                after: $this->snapshot($membership),
                reason: 'Removed as team lead of '.$team->name.'.',
                sourceContext: $sourceContext,
            );

            return $membership->load('team');
        });
    }

    /**
     * @throws TeamAdminException
     */
    private function assertTeamEligible(Team $team): void
    {
        if ($team->isArchived()) {
            throw new TeamAdminException('Archived teams cannot receive lead designations.');
        }

        $department = $team->department;

        if ($department === null) {
            throw new TeamAdminException('The selected team must belong to a department.');
        }

        if ($department->isArchived()) {
            throw new TeamAdminException('Archived departments cannot receive lead designations.');
        }
    }

    /**
     * @throws TeamAdminException
     */
    private function assertStaffMayBeStaffed(Team $team, Staff $staff): void
    {
        $organizationId = $team->department?->organization_id;

        if ($organizationId === null) {
            return;
        }

        $status = StaffOrganizationStatus::query()
            ->where('organization_id', $organizationId)
            ->where('staff_id', $staff->id)
            ->first();

        if ($status?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
            throw new TeamAdminException('Do Not Staff records cannot be designated as team leads.');
        }
    }

    private function ensureShiftLeadGrant(Team $team): void
    {
        $role = PermissionRole::query()
            ->where('code', PermissionCatalog::ROLE_SHIFT_LEAD)
            ->firstOrFail();

        $hasGrant = TeamGrant::query()
            ->active()
            ->whereNull('event_id')
            ->where('team_id', $team->id)
            ->where('permission_role_id', $role->id)
            ->exists();

        if (! $hasGrant) {
            $this->teamGrants->grant($team, $role);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TeamMembership $membership): array
    {
        return [
            'team_id' => (string) $membership->team_id,
            'staff_id' => (string) $membership->staff_id,
            'department_membership_id' => (string) $membership->department_membership_id,
            'membership_role' => $membership->membership_role,
            'archived_at' => $membership->archived_at?->toISOString(),
        ];
    }
}
