<?php

namespace App\Services\Staffing;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Permissions\TeamGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * MVP product-path staff intake and department lead selection (M11.14).
 */
final class OrganizerStaffService
{
    private const LEAD_TEAM_CODE = 'LEADS';

    public function __construct(
        private readonly AuditService $audit,
        private readonly DepartmentMembershipService $departmentMemberships,
        private readonly TeamGrantService $teamGrants,
    ) {}

    /**
     * @param  array{
     *     legal_name: string,
     *     email: string,
     *     preferred_name?: string|null,
     *     handle?: string|null,
     *     phone?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     status?: string|null,
     *     department_id?: string|null,
     *     invite?: bool|null
     * }  $attributes
     */
    public function addStaff(
        Organization $organization,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Staff {
        $status = (string) ($attributes['status'] ?? StaffOrganizationStatus::STATUS_PROSPECTIVE);
        if (! in_array($status, [
            StaffOrganizationStatus::STATUS_PROSPECTIVE,
            StaffOrganizationStatus::STATUS_ACTIVE,
            StaffOrganizationStatus::STATUS_INACTIVE,
        ], true)) {
            throw new OrganizerStaffException('Staff intake status must be Prospective, Active, or Inactive.');
        }

        $department = $this->optionalDepartment($organization, $attributes['department_id'] ?? null);

        return DB::transaction(function () use ($organization, $attributes, $actor, $sourceContext, $status, $department): Staff {
            $email = Str::lower(trim($attributes['email']));
            $legalName = trim($attributes['legal_name']);

            if ($legalName === '' || $email === '') {
                throw new OrganizerStaffException('Legal name and email are required.');
            }

            $staff = Staff::query()->where('email', $email)->lockForUpdate()->first();
            $createdStaff = false;

            if ($staff === null) {
                $staff = Staff::query()->create([
                    'legal_name' => $legalName,
                    'preferred_name' => $this->nullableTrim($attributes['preferred_name'] ?? null),
                    'handle' => $this->nullableTrim($attributes['handle'] ?? null),
                    'email' => $email,
                    'phone' => $this->nullableTrim($attributes['phone'] ?? null),
                    'city' => $this->nullableTrim($attributes['city'] ?? null),
                    'state' => $this->nullableTrim($attributes['state'] ?? null),
                ]);
                $createdStaff = true;
            } elseif ($staff->isArchived()) {
                throw new OrganizerStaffException('Archived staff records cannot be added through intake.');
            }

            $existingStatus = StaffOrganizationStatus::query()
                ->where('organization_id', $organization->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existingStatus !== null) {
                throw new OrganizerStaffException('This staff member is already in the organization.');
            }

            StaffOrganizationStatus::query()->create([
                'organization_id' => $organization->id,
                'staff_id' => $staff->id,
                'status' => $status,
                'status_reason' => 'Added by organizer staff intake.',
                'status_changed_at' => now(),
                'status_changed_by_user_id' => $actor->id,
            ]);

            if (($attributes['invite'] ?? false) === true) {
                $this->ensureInvitedUser($staff);
            }

            if ($department !== null) {
                $this->ensureDepartmentMembership($staff, $department, $actor);
            }

            $staff->refresh()->load(['organizationStatuses', 'departmentMemberships.department', 'teamMemberships.team']);

            $this->audit->recordForEntity(
                entity: $staff,
                action: $createdStaff ? 'staff.created' : 'staff.added_to_organization',
                actorUser: $actor,
                organizationId: (string) $organization->id,
                departmentId: $department?->id,
                after: $this->staffSnapshot($staff, $organization),
                sourceContext: $sourceContext,
            );

            return $staff;
        });
    }

    public function selectDepartmentLead(
        Staff $staff,
        Department $department,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamMembership {
        $this->assertStaffBelongsToOrganization($staff, $department->organization);

        return DB::transaction(function () use ($staff, $department, $actor, $sourceContext): TeamMembership {
            $department = Department::query()
                ->with(['organization', 'defaultTeam'])
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($department->isArchived()) {
                throw new OrganizerStaffException('Archived departments cannot receive department lead selections.');
            }

            $departmentMembership = $this->ensureDepartmentMembership($staff, $department, $actor);
            $leadTeam = $this->ensureLeadTeam($department);
            $this->ensureDepartmentLeadGrant($leadTeam);

            $existing = TeamMembership::query()
                ->where('team_id', $leadTeam->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->archived_at === null) {
                return $existing->load('team');
            }

            if ($existing !== null) {
                $before = $this->teamMembershipSnapshot($existing);
                $existing->forceFill([
                    'department_membership_id' => $departmentMembership->id,
                    'membership_role' => 'lead',
                    'archived_at' => null,
                ])->save();
                $membership = $existing->refresh();
                $action = 'department_lead.reactivated';
            } else {
                $membership = $departmentMembership->teamMemberships()->create([
                    'team_id' => $leadTeam->id,
                    'staff_id' => $staff->id,
                    'membership_role' => 'lead',
                ]);
                $before = null;
                $action = 'department_lead.selected';
            }

            $this->audit->recordForEntity(
                entity: $membership,
                action: $action,
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $this->teamMembershipSnapshot($membership),
                sourceContext: $sourceContext,
            );

            return $membership->load('team');
        });
    }

    public function removeDepartmentLead(
        Staff $staff,
        Department $department,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TeamMembership {
        return DB::transaction(function () use ($staff, $department, $actor, $sourceContext): TeamMembership {
            $leadTeam = Team::query()
                ->where('department_id', $department->id)
                ->where('code', self::LEAD_TEAM_CODE)
                ->first();

            if ($leadTeam === null) {
                throw new OrganizerStaffException('This staff member is not a department lead.');
            }

            $membership = TeamMembership::query()
                ->active()
                ->where('team_id', $leadTeam->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw new OrganizerStaffException('This staff member is not a department lead.');
            }

            $before = $this->teamMembershipSnapshot($membership);
            $membership->forceFill(['archived_at' => now()])->save();
            $membership->refresh();

            $this->audit->recordForEntity(
                entity: $membership,
                action: 'department_lead.removed',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                before: $before,
                after: $this->teamMembershipSnapshot($membership),
                sourceContext: $sourceContext,
            );

            return $membership->load('team');
        });
    }

    private function optionalDepartment(Organization $organization, ?string $departmentId): ?Department
    {
        if ($departmentId === null || $departmentId === '') {
            return null;
        }

        $department = Department::query()->find($departmentId);

        if ($department === null || (string) $department->organization_id !== (string) $organization->id) {
            throw new OrganizerStaffException('Department must belong to the organization.');
        }

        if ($department->isArchived()) {
            throw new OrganizerStaffException('Archived departments cannot receive staff intake.');
        }

        return $department;
    }

    private function ensureDepartmentMembership(Staff $staff, Department $department, User $actor): DepartmentMembership
    {
        $membership = DepartmentMembership::query()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            return $this->departmentMemberships->assignStaffWithDefaultTeam($staff, $department, $actor);
        }

        if ($membership->status === DepartmentMembership::STATUS_INELIGIBLE) {
            throw new OrganizerStaffException('Ineligible department memberships cannot receive staff intake or lead selection.');
        }

        if ($membership->archived_at !== null) {
            $membership->forceFill([
                'status' => DepartmentMembership::STATUS_ACTIVE,
                'status_reason' => 'Reactivated by organizer staff intake.',
                'archived_at' => null,
            ])->save();
        } elseif ($membership->status !== DepartmentMembership::STATUS_ACTIVE) {
            $membership->forceFill([
                'status' => DepartmentMembership::STATUS_ACTIVE,
                'status_reason' => 'Activated by organizer staff intake.',
            ])->save();
        }

        $defaultTeam = $department->defaultTeam()->first();
        if ($defaultTeam === null) {
            throw new OrganizerStaffException('Department must have a default team before staff assignment.');
        }

        $defaultMembership = TeamMembership::query()
            ->where('team_id', $defaultTeam->id)
            ->where('staff_id', $staff->id)
            ->lockForUpdate()
            ->first();

        if ($defaultMembership === null) {
            $membership->teamMemberships()->create([
                'team_id' => $defaultTeam->id,
                'staff_id' => $staff->id,
                'membership_role' => 'member',
            ]);
        } elseif ($defaultMembership->archived_at !== null) {
            $defaultMembership->forceFill(['archived_at' => null])->save();
        }

        return $membership->refresh();
    }

    private function ensureInvitedUser(Staff $staff): User
    {
        $user = User::query()->where('email', $staff->email)->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => $staff->preferred_name ?: $staff->legal_name,
                'email' => $staff->email,
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => null,
            ]);
        }

        if (! $user->staffProfiles()->whereKey($staff->id)->exists()) {
            $user->staffProfiles()->attach($staff->id);
        }

        return $user;
    }

    private function ensureLeadTeam(Department $department): Team
    {
        $team = Team::query()
            ->where('department_id', $department->id)
            ->where('code', self::LEAD_TEAM_CODE)
            ->first();

        if ($team !== null) {
            return $team;
        }

        return Team::query()->create([
            'department_id' => $department->id,
            'name' => 'Department Leads',
            'code' => self::LEAD_TEAM_CODE,
            'description' => 'Department lead authority team.',
            'is_default' => false,
        ]);
    }

    private function ensureDepartmentLeadGrant(Team $team): void
    {
        $role = PermissionRole::query()
            ->where('code', PermissionCatalog::ROLE_DEPARTMENT_LEAD)
            ->firstOrFail();

        $hasGrant = TeamGrant::query()
            ->active()
            ->where('team_id', $team->id)
            ->where('permission_role_id', $role->id)
            ->exists();

        if (! $hasGrant) {
            $this->teamGrants->grant($team, $role);
        }
    }

    private function assertStaffBelongsToOrganization(Staff $staff, ?Organization $organization): void
    {
        if ($organization === null) {
            throw new OrganizerStaffException('Department must belong to an organization.');
        }

        $status = StaffOrganizationStatus::query()
            ->where('organization_id', $organization->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($status === null) {
            throw new OrganizerStaffException('Department leads must already belong to the organization.');
        }

        if ($status->status === StaffOrganizationStatus::STATUS_DO_NOT_STAFF) {
            throw new OrganizerStaffException('Do Not Staff records cannot be selected as department leads.');
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function staffSnapshot(Staff $staff, Organization $organization): array
    {
        $status = $staff->organizationStatuses
            ->firstWhere('organization_id', $organization->id);

        return [
            'id' => (string) $staff->id,
            'legal_name' => $staff->legal_name,
            'preferred_name' => $staff->preferred_name,
            'handle' => $staff->handle,
            'email' => $staff->email,
            'organization_status' => $status?->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamMembershipSnapshot(TeamMembership $membership): array
    {
        return [
            'team_id' => (string) $membership->team_id,
            'staff_id' => (string) $membership->staff_id,
            'department_membership_id' => (string) $membership->department_membership_id,
            'membership_role' => $membership->membership_role,
            'archived_at' => $membership->archived_at?->toIso8601String(),
        ];
    }
}
