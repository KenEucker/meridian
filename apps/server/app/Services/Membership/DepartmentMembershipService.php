<?php

namespace App\Services\Membership;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\NotificationType;
use App\Services\Status\StaffStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DepartmentMembershipService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly NotificationRecipientResolver $notificationRecipients,
    ) {}

    /**
     * Assign staff to a department using the department default team only.
     *
     * Alpha 1 department assignment (APP-007) satisfies VOL-006 with the
     * structural default team; operational team assignment is delivered by
     * {@see TeamMembershipService::assignStaffToTeam}.
     */
    public function assignStaffWithDefaultTeam(
        Staff $staff,
        Department $department,
        ?User $assignedBy = null,
        ?string $statusReason = null,
    ): DepartmentMembership {
        $department->loadMissing('defaultTeam');

        if ($department->defaultTeam === null) {
            throw new InvalidArgumentException('Department must have a default team before assignment.');
        }

        return $this->createWithTeams(
            $staff,
            $department,
            [$department->defaultTeam],
            DepartmentMembership::STATUS_ACTIVE,
            $statusReason ?? 'Assigned to department after application approval.',
            $assignedBy,
        );
    }

    /**
     * @param  iterable<int, Team>|Collection<int, Team>  $teams
     */
    public function createWithTeams(
        Staff $staff,
        Department $department,
        iterable $teams,
        string $status = DepartmentMembership::STATUS_ACTIVE,
        ?string $statusReason = null,
        ?User $changedBy = null,
    ): DepartmentMembership {
        $teams = collect($teams)->values();

        if ($teams->isEmpty()) {
            throw new InvalidArgumentException('Department membership requires at least one team.');
        }

        if (! in_array($status, DepartmentMembership::statuses(), true)) {
            throw new InvalidArgumentException('Unsupported department membership status.');
        }

        $teams->each(function (Team $team) use ($department): void {
            if ((string) $team->department_id !== (string) $department->id) {
                throw new InvalidArgumentException('All team memberships must belong to the assigned department.');
            }
        });

        return DB::transaction(function () use ($staff, $department, $teams, $status, $statusReason, $changedBy): DepartmentMembership {
            $departmentMembership = DepartmentMembership::query()->create([
                'department_id' => $department->id,
                'staff_id' => $staff->id,
                'status' => $status,
                'status_reason' => $statusReason,
            ]);

            $teams->each(function (Team $team) use ($staff, $departmentMembership): void {
                $departmentMembership->teamMemberships()->create([
                    'team_id' => $team->id,
                    'staff_id' => $staff->id,
                    'membership_role' => 'member',
                ]);
            });

            if ($status === DepartmentMembership::STATUS_ACTIVE) {
                app(StaffStatusService::class)->activateProspectiveOrganizationStatusForDepartmentAssignment(
                    $departmentMembership,
                    $changedBy,
                );
            }

            $departmentMembership = $departmentMembership->refresh()->load('teamMemberships.team');

            if ($status === DepartmentMembership::STATUS_ACTIVE) {
                $this->notifyAddition($departmentMembership, $department, $staff, $changedBy);
            }

            return $departmentMembership;
        });
    }

    /**
     * One notification for the department and the teams it came with
     * (NOTIFY-001, NOTIFY-001A).
     *
     * The collapse the requirement asks for falls out of where this call sits.
     * A staff member cannot belong to a department without belonging to a team
     * (VOL-006), so department assignment creates both here in one transaction
     * and sends one message naming both. The separate team-addition path
     * refuses to run without an existing department membership, so it can only
     * ever be the later addition NOTIFY-001A says gets its own notification.
     *
     * Only an active membership notifies. An Ineligible or Inactive membership
     * created for record-keeping has not changed what the staff member may do,
     * which is the whole test NOTIFY-001 applies.
     */
    private function notifyAddition(
        DepartmentMembership $membership,
        Department $department,
        Staff $staff,
        ?User $changedBy,
    ): void {
        $department->loadMissing('organization');

        $this->notifications->dispatch(
            type: NotificationType::DepartmentMembershipAdded,
            subject: $membership,
            recipient: $this->notificationRecipients->forStaff($staff),
            organization: $department->organization,
            department: $department,
            actor: $changedBy,
        );
    }
}
