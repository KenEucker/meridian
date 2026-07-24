<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_lead_can_update_department_details_and_manage_teams(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $defaultTeam = Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();

        $this->actingAs($actor)
            ->postJson('/api/commands/update-department-details', [
                'department_id' => $department->id,
                'name' => '  Rangers Updated  ',
                'code' => 'RANGERS',
                'description' => ' Updated description. ',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Rangers Updated')
            ->assertJsonPath('code', 'RANGERS')
            ->assertJsonPath('description', 'Updated description.');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'department.updated',
            'entity_id' => $department->id,
            'actor_user_id' => $actor->id,
        ]);

        $create = $this->actingAs($actor)
            ->postJson('/api/commands/create-team', [
                'department_id' => $department->id,
                'name' => '  Operators  ',
                'code' => 'OPERATORS',
                'description' => ' Radio ops. ',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Operators')
            ->assertJsonPath('code', 'OPERATORS')
            ->assertJsonPath('description', 'Radio ops.')
            ->assertJsonPath('is_default', false)
            ->assertJsonPath('department_id', $department->id)
            ->assertJsonPath('archived_at', null);

        $teamId = (string) $create->json('id');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'team.created',
            'entity_id' => $teamId,
            'actor_user_id' => $actor->id,
            'department_id' => $department->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams")
            ->assertOk()
            ->assertJsonPath('department_id', $department->id)
            ->assertJsonPath('department.name', 'Rangers Updated')
            ->assertJsonFragment(['id' => $teamId, 'code' => 'OPERATORS'])
            ->assertJsonFragment(['id' => $defaultTeam->id, 'is_default' => true]);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-team', [
                'team_id' => $defaultTeam->id,
                'name' => 'Rangers Default Renamed',
                'code' => 'DEFAULT',
                'description' => 'Default team rename.',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Rangers Default Renamed')
            ->assertJsonPath('is_default', true);

        $defaultTeam->refresh();
        $this->assertTrue($defaultTeam->is_default);
        $this->assertSame($defaultTeam->id, $department->fresh()->default_team_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'team.updated',
            'entity_id' => $defaultTeam->id,
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-team', [
                'team_id' => $teamId,
            ])
            ->assertOk()
            ->assertJsonPath('id', $teamId);

        $this->assertNotNull(Team::query()->findOrFail($teamId)->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'team.archived',
            'entity_id' => $teamId,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams?status=archived")
            ->assertOk()
            ->assertJsonFragment(['id' => $teamId]);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams?status=active")
            ->assertOk()
            ->assertJsonMissing(['id' => $teamId])
            ->assertJsonFragment(['id' => $defaultTeam->id]);

        $this->actingAs($actor)
            ->postJson('/api/commands/restore-team', [
                'team_id' => $teamId,
            ])
            ->assertOk()
            ->assertJsonPath('archived_at', null);

        $this->assertNull(Team::query()->findOrFail($teamId)->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'team.restored',
            'entity_id' => $teamId,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams/{$teamId}")
            ->assertOk()
            ->assertJsonPath('name', 'Operators')
            ->assertJsonPath('archived_at', null);
    }

    public function test_department_administration_role_can_create_team(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_administration');

        $this->actingAs($actor)
            ->postJson('/api/commands/create-team', [
                'department_id' => $department->id,
                'name' => 'Planning Support',
                'code' => 'PLAN_SUPPORT',
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'PLAN_SUPPORT');
    }

    public function test_team_lead_gets_scoped_read_only_team_and_staff_view(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);
        $ledTeam = Team::factory()->for($department)->create([
            'name' => 'Dirt',
            'code' => 'DIRT',
        ]);
        $peerTeam = Team::factory()->for($department)->create([
            'name' => 'Operators',
            'code' => 'OPERATORS',
        ]);

        $actor = User::factory()->create();
        $actorStaff = Staff::factory()->create([
            'preferred_name' => 'Sam',
            'legal_name' => 'Sam Shiftlead',
            'handle' => 'samshift',
        ]);
        $actor->staffProfiles()->attach($actorStaff->id);
        $actorDepartmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($actorStaff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $ledTeam->id,
            'staff_id' => $actorStaff->id,
            'department_membership_id' => $actorDepartmentMembership->id,
            'membership_role' => 'lead',
        ]);
        TeamGrant::factory()->create([
            'team_id' => $ledTeam->id,
            'event_id' => null,
            'permission_role_id' => PermissionRole::query()->where('code', 'shift_lead')->firstOrFail()->id,
        ]);

        $assignedStaff = Staff::factory()->create([
            'preferred_name' => 'Vera',
            'legal_name' => 'Vera Staff',
            'handle' => 'verastaff',
        ]);
        $assignedDepartmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($assignedStaff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $ledTeam->id,
            'staff_id' => $assignedStaff->id,
            'department_membership_id' => $assignedDepartmentMembership->id,
            'membership_role' => 'member',
        ]);

        $peerStaff = Staff::factory()->create(['preferred_name' => 'Omar']);
        $peerDepartmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($peerStaff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $peerTeam->id,
            'staff_id' => $peerStaff->id,
            'department_membership_id' => $peerDepartmentMembership->id,
            'membership_role' => 'member',
        ]);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams")
            ->assertOk()
            ->assertJsonPath('access.can_administer', false)
            ->assertJsonPath('access.can_view_led_teams', true)
            ->assertJsonFragment(['id' => $ledTeam->id, 'code' => 'DIRT'])
            ->assertJsonMissing(['id' => $peerTeam->id, 'code' => 'OPERATORS'])
            ->assertJsonFragment([
                'staff_id' => $assignedStaff->id,
                'display_name' => 'Vera',
                'handle' => 'verastaff',
                'team_id' => $ledTeam->id,
                'team_name' => 'Dirt',
                'membership_role' => 'member',
            ])
            // Per-team staff lists stay scoped to led teams; the peer team's
            // roster never appears with a team association. The flat
            // department_staff list is the M11.17 assignment-candidate roster
            // (names/handles only) team leads use to assign permitted staff.
            ->assertJsonMissing(['team_id' => $peerTeam->id])
            ->assertJsonPath('department_staff.2.staff_id', $assignedStaff->id);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams/{$ledTeam->id}")
            ->assertOk()
            ->assertJsonPath('access.can_administer', false)
            ->assertJsonPath('access.can_view_led_team', true)
            ->assertJsonPath('id', $ledTeam->id)
            ->assertJsonFragment(['staff_id' => $assignedStaff->id]);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams/{$peerTeam->id}")
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/create-team', [
                'department_id' => $department->id,
                'name' => 'Denied',
                'code' => 'DENIED',
            ])
            ->assertForbidden();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['code' => 'RANGERS']);
        $team = Team::query()->where('department_id', $department->id)->where('is_default', true)->firstOrFail();
        $actor = User::factory()->create();
        $staff = Staff::factory()->create();
        $actor->staffProfiles()->attach($staff->id);

        $this->actingAs($actor)
            ->getJson("/api/departments/{$department->id}/teams")
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/create-team', [
                'department_id' => $department->id,
                'name' => 'Denied',
                'code' => 'DENIED',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/update-department-details', [
                'department_id' => $department->id,
                'name' => 'Denied',
                'code' => 'RANGERS',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-team', [
                'team_id' => $team->id,
            ])
            ->assertForbidden();
    }

    public function test_self_admin_cannot_manage_another_department(): void
    {
        [, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $otherDepartment = Department::factory()->create(['code' => 'GATE']);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-team', [
                'department_id' => $otherDepartment->id,
                'name' => 'Cross Dept',
                'code' => 'CROSS',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/update-department-details', [
                'department_id' => $otherDepartment->id,
                'name' => 'Cross Dept',
                'code' => 'GATE',
            ])
            ->assertForbidden();
    }

    public function test_default_team_cannot_be_archived(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $defaultTeam = Team::query()
            ->where('department_id', $department->id)
            ->where('is_default', true)
            ->firstOrFail();

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-team', [
                'team_id' => $defaultTeam->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Default teams cannot be archived.');
    }

    public function test_duplicate_team_code_is_rejected(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        Team::factory()->for($department)->create(['code' => 'OPERATORS']);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-team', [
                'department_id' => $department->id,
                'name' => 'Operators Two',
                'code' => 'OPERATORS',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_archive_and_restore_are_idempotent_guards(): void
    {
        [$department, $actor] = $this->departmentWithSelfAdmin('department_lead');
        $team = Team::factory()->for($department)->create(['code' => 'SWING']);

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-team', [
                'team_id' => $team->id,
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-team', [
                'team_id' => $team->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Team is already archived.');

        $this->actingAs($actor)
            ->postJson('/api/commands/restore-team', [
                'team_id' => $team->id,
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->postJson('/api/commands/restore-team', [
                'team_id' => $team->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Team is not archived.');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $department = Department::factory()->create();

        $this->postJson('/api/commands/create-team', [
            'department_id' => $department->id,
            'name' => 'Gate',
            'code' => 'GATE',
        ])->assertUnauthorized();

        $this->getJson("/api/departments/{$department->id}/teams")
            ->assertUnauthorized();
    }

    /**
     * @return array{0: Department, 1: User}
     */
    private function departmentWithSelfAdmin(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);

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
}
