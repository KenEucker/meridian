<?php

namespace App\Services\Membership;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Operational team assignment for staff who already belong to a department
 * (requirements section 5.4; TEAM-008, TEAM-009).
 *
 * Structural default-team membership during department assignment is delivered
 * by {@see DepartmentMembershipService::assignStaffWithDefaultTeam}; this service
 * adds or restores membership on additional teams within the same department.
 */
class TeamMembershipService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamAssignmentAccess $teamAssignmentAccess,
    ) {}

    /**
     * @throws TeamAssignmentException when assignment is not permitted or valid
     */
    public function assignStaffToTeam(
        Staff $staff,
        Team $team,
        User $assigner,
    ): TeamMembership {
        if (! $this->teamAssignmentAccess->canAssignToTeam($assigner, $team)) {
            throw new TeamAssignmentException('You are not authorized to assign this staff member to the selected team.');
        }

        return DB::transaction(function () use ($staff, $team, $assigner): TeamMembership {
            $team = Team::query()
                ->with('department')
                ->whereKey($team->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTeamEligibleForAssignment($team);

            $departmentMembership = DepartmentMembership::query()
                ->active()
                ->where('department_id', $team->department_id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($departmentMembership === null) {
                throw new TeamAssignmentException('Staff must belong to the department before team assignment.');
            }

            $organizationId = $team->department?->organization_id;

            if ($organizationId !== null) {
                $organizationStatus = StaffOrganizationStatus::query()
                    ->where('organization_id', $organizationId)
                    ->where('staff_id', $staff->id)
                    ->lockForUpdate()
                    ->first();

                if ($organizationStatus?->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
                    throw new TeamAssignmentException('Do Not Staff records cannot be assigned to teams.');
                }
            }

            $existingMembership = TeamMembership::query()
                ->where('team_id', $team->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existingMembership !== null && $existingMembership->archived_at === null) {
                throw new TeamAssignmentException('This staff member is already assigned to the selected team.');
            }

            $reason = 'Assigned to '.$team->name.' in '.$team->department?->name.'.';

            if ($existingMembership !== null) {
                $before = $this->teamMembershipAuditSnapshot($existingMembership);

                $existingMembership->forceFill([
                    'department_membership_id' => $departmentMembership->id,
                    'membership_role' => 'member',
                    'archived_at' => null,
                ])->save();

                $teamMembership = $existingMembership->refresh();
                $auditAction = 'team_membership.reactivated';
            } else {
                $teamMembership = $departmentMembership->teamMemberships()->create([
                    'team_id' => $team->id,
                    'staff_id' => $staff->id,
                    'membership_role' => 'member',
                ]);
                $before = null;
                $auditAction = 'team_membership.assigned';
            }

            $this->audit->recordForEntity(
                entity: $teamMembership,
                action: $auditAction,
                actorUser: $assigner,
                organizationId: $organizationId,
                departmentId: $team->department_id,
                before: $before,
                after: $this->teamMembershipAuditSnapshot($teamMembership),
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );

            return $teamMembership->load('team');
        });
    }

    /**
     * @throws TeamAssignmentException
     */
    private function assertTeamEligibleForAssignment(Team $team): void
    {
        if ($team->isArchived()) {
            throw new TeamAssignmentException('Archived teams cannot receive new assignments.');
        }

        $department = $team->department;

        if ($department === null) {
            throw new TeamAssignmentException('The selected team must belong to a department.');
        }

        if ($department->isArchived()) {
            throw new TeamAssignmentException('Archived departments cannot receive team assignments.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function teamMembershipAuditSnapshot(TeamMembership $teamMembership): array
    {
        return [
            'team_id' => $teamMembership->team_id,
            'staff_id' => $teamMembership->staff_id,
            'department_membership_id' => $teamMembership->department_membership_id,
            'membership_role' => $teamMembership->membership_role,
            'archived_at' => $teamMembership->archived_at?->toISOString(),
        ];
    }
}
