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
