<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product-path team lead designation and team staff assignment (M11.17;
 * TEAM-008, TEAM-009; requirements 5.4).
 */
class TeamLeadAndStaffAssignmentHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_lead_designates_and_removes_a_team_lead(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        [$staff, $staffUser] = $this->departmentStaff($department, 'Sam');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/select-team-lead', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertCreated()
            ->assertJsonPath('membership_role', 'lead')
            ->assertJsonPath('team_id', $team->id)
            ->assertJsonPath('staff_id', $staff->id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'team_lead.selected',
            'actor_user_id' => $actor->id,
            'department_id' => $department->id,
        ]);

        $shiftLeadRole = PermissionRole::query()->where('code', 'shift_lead')->firstOrFail();
        $this->assertDatabaseHas('team_grants', [
            'team_id' => $team->id,
            'permission_role_id' => $shiftLeadRole->id,
            'revoked_at' => null,
        ]);

        // The designated lead now has scoped team-lead access to the led team.
        $this->actingAsClient($staffUser)
            ->getJson("/api/departments/{$department->id}/teams")
            ->assertOk()
            ->assertJsonPath('access.can_view_led_teams', true)
            ->assertJsonPath('access.led_team_ids.0', $team->id);

        // Removing the lead keeps the membership but drops lead authority.
        $this->actingAsClient($actor)
            ->postJson('/api/commands/remove-team-lead', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertOk()
            ->assertJsonPath('membership_role', 'member')
            ->assertJsonPath('archived_at', null);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'team_lead.removed',
            'actor_user_id' => $actor->id,
        ]);

        $this->actingAsClient($staffUser)
            ->getJson("/api/departments/{$department->id}/teams")
            ->assertForbidden();
    }

    public function test_team_lead_and_other_department_admin_cannot_designate_leads(): void
    {
        [$department] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        [$staff] = $this->departmentStaff($department, 'Sam');

        [$teamLeadUser] = $this->teamLeadFor($team);

        $this->actingAsClient($teamLeadUser)
            ->postJson('/api/commands/select-team-lead', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertForbidden();

        [, $otherAdmin] = $this->departmentWithSelfAdmin('department_lead');

        $this->actingAsClient($otherAdmin)
            ->postJson('/api/commands/select-team-lead', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertForbidden();
    }

    public function test_lead_designation_requires_department_membership(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        $outsideStaff = Staff::factory()->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/select-team-lead', [
                'staff_id' => $outsideStaff->id,
                'team_id' => $team->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Staff must belong to the department before team lead designation.');
    }

    public function test_team_lead_assigns_and_removes_staff_on_led_team_only(): void
    {
        [$department] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['name' => 'Dirt', 'code' => 'DIRT']);
        $peerTeam = Team::factory()->for($department)->create(['name' => 'Operators', 'code' => 'OPERATORS']);
        [$staff] = $this->departmentStaff($department, 'Vera', assignDefaultTeam: true);

        [$teamLeadUser] = $this->teamLeadFor($team);

        $this->actingAsClient($teamLeadUser)
            ->postJson('/api/commands/assign-staff-to-team', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertCreated()
            ->assertJsonPath('membership_role', 'member')
            ->assertJsonPath('team_id', $team->id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'team_membership.assigned',
            'actor_user_id' => $teamLeadUser->id,
            'source_context' => 'api',
        ]);

        $this->actingAsClient($teamLeadUser)
            ->postJson('/api/commands/assign-staff-to-team', [
                'staff_id' => $staff->id,
                'team_id' => $peerTeam->id,
            ])
            ->assertForbidden();

        $this->actingAsClient($teamLeadUser)
            ->postJson('/api/commands/remove-staff-from-team', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertOk();

        $membership = TeamMembership::query()
            ->where('team_id', $team->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();
        $this->assertNotNull($membership->archived_at);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'team_membership.removed',
            'actor_user_id' => $teamLeadUser->id,
        ]);
    }

    public function test_department_lead_assigns_staff_to_any_department_team(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        [$staff] = $this->departmentStaff($department, 'Vera');

        $this->actingAsClient($actor)
            ->postJson('/api/commands/assign-staff-to-team', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertCreated();
    }

    public function test_staff_cannot_be_removed_from_default_team(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $defaultTeam = Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();
        [$staff] = $this->departmentStaff($department, 'Vera', assignDefaultTeam: true);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/remove-staff-from-team', [
                'staff_id' => $staff->id,
                'team_id' => $defaultTeam->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Staff cannot be removed from the department default team.');
    }

    public function test_do_not_staff_records_cannot_be_assigned_or_designated(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        [$staff] = $this->departmentStaff($department, 'Blocked');

        StaffOrganizationStatus::query()->updateOrCreate(
            [
                'organization_id' => $department->organization_id,
                'staff_id' => $staff->id,
            ],
            ['status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF],
        );

        $this->actingAsClient($actor)
            ->postJson('/api/commands/assign-staff-to-team', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertUnprocessable();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/select-team-lead', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Do Not Staff records cannot be designated as team leads.');
    }

    public function test_staff_without_authority_cannot_manage_team_staff(): void
    {
        [$department] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['code' => 'DIRT']);
        [$staff] = $this->departmentStaff($department, 'Vera');
        [, $plainUser] = $this->departmentStaff($department, 'Plain');

        $this->actingAsClient($plainUser)
            ->postJson('/api/commands/assign-staff-to-team', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertForbidden();

        $this->actingAsClient($plainUser)
            ->postJson('/api/commands/remove-staff-from-team', [
                'staff_id' => $staff->id,
                'team_id' => $team->id,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Department, 1: User}
     */
    private function departmentWithSelfAdmin(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();

        $team = Team::query()->where('department_id', $department->id)->where('is_default', true)->first()
            ?? Team::factory()->for($department)->create(['is_default' => true]);

        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        return [$department, $user];
    }

    /**
     * @return array{0: Staff, 1: User}
     */
    private function departmentStaff(
        Department $department,
        string $name,
        bool $assignDefaultTeam = false,
    ): array {
        $staff = Staff::factory()->create([
            'preferred_name' => $name,
            'legal_name' => $name.' Staff',
        ]);
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();

        if ($assignDefaultTeam) {
            $defaultTeam = Team::query()
                ->where('department_id', $department->id)
                ->where('is_default', true)
                ->firstOrFail();

            TeamMembership::factory()->create([
                'team_id' => $defaultTeam->id,
                'staff_id' => $staff->id,
                'department_membership_id' => $membership->id,
            ]);
        }

        return [$staff, $user];
    }

    /**
     * @return array{0: User, 1: Staff}
     */
    private function teamLeadFor(Team $team): array
    {
        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => 'lead',
        ]);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', 'shift_lead')->firstOrFail()->id,
        ]);

        return [$user, $staff];
    }
}
