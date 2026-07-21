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

class DepartmentAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_create_list_update_archive_and_restore_department(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');

        $create = $this->actingAs($actor)
            ->postJson('/api/commands/create-department', [
                'organization_id' => $organization->id,
                'name' => '  Rangers  ',
                'code' => 'RANGERS',
                'description' => ' Field operations. ',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Rangers')
            ->assertJsonPath('code', 'RANGERS')
            ->assertJsonPath('description', 'Field operations.')
            ->assertJsonPath('organization_id', $organization->id)
            ->assertJsonPath('archived_at', null);

        $departmentId = (string) $create->json('id');
        $defaultTeamId = (string) $create->json('default_team_id');

        $this->assertNotSame('', $defaultTeamId);
        $this->assertDatabaseHas('teams', [
            'id' => $defaultTeamId,
            'department_id' => $departmentId,
            'code' => 'DEFAULT',
            'is_default' => true,
        ]);

        $createdAudit = AuditEvent::query()->where('action', 'department.created')->sole();
        $this->assertSame($actor->id, $createdAudit->actor_user_id);
        $this->assertSame($organization->id, $createdAudit->organization_id);
        $this->assertSame($departmentId, $createdAudit->department_id);
        $this->assertSame(AuditEvent::SOURCE_API, $createdAudit->source_context);

        $this->actingAs($actor)
            ->getJson("/api/organizations/{$organization->id}/departments")
            ->assertOk()
            ->assertJsonPath('organization_id', $organization->id)
            ->assertJsonFragment(['id' => $departmentId, 'code' => 'RANGERS']);

        $this->actingAs($actor)
            ->postJson('/api/commands/update-department', [
                'department_id' => $departmentId,
                'name' => 'Rangers Updated',
                'code' => 'RANGERS',
                'description' => 'Updated description.',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Rangers Updated')
            ->assertJsonPath('description', 'Updated description.');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'department.updated',
            'entity_id' => $departmentId,
            'actor_user_id' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-department', [
                'department_id' => $departmentId,
            ])
            ->assertOk()
            ->assertJsonPath('id', $departmentId);

        $this->assertNotNull(Department::query()->findOrFail($departmentId)->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'department.archived',
            'entity_id' => $departmentId,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/organizations/{$organization->id}/departments?status=archived")
            ->assertOk()
            ->assertJsonFragment(['id' => $departmentId]);

        $this->actingAs($actor)
            ->getJson("/api/organizations/{$organization->id}/departments?status=active")
            ->assertOk()
            ->assertJsonMissing(['id' => $departmentId]);

        $this->actingAs($actor)
            ->postJson('/api/commands/restore-department', [
                'department_id' => $departmentId,
            ])
            ->assertOk()
            ->assertJsonPath('archived_at', null);

        $this->assertNull(Department::query()->findOrFail($departmentId)->archived_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'department.restored',
            'entity_id' => $departmentId,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/organizations/{$organization->id}/departments/{$departmentId}")
            ->assertOk()
            ->assertJsonPath('name', 'Rangers Updated')
            ->assertJsonPath('archived_at', null);
    }

    public function test_lead_organizer_can_create_department(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('lead_organizer');

        $this->actingAs($actor)
            ->postJson('/api/commands/create-department', [
                'organization_id' => $organization->id,
                'name' => 'Gate',
                'code' => 'GATE',
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'GATE');
    }

    public function test_non_organizer_is_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['code' => 'EXISTING']);
        $actor = User::factory()->create();
        $staff = Staff::factory()->create();
        $actor->staffProfiles()->attach($staff->id);

        $this->actingAs($actor)
            ->getJson("/api/organizations/{$organization->id}/departments")
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/create-department', [
                'organization_id' => $organization->id,
                'name' => 'Denied',
                'code' => 'DENIED',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/update-department', [
                'department_id' => $department->id,
                'name' => 'Denied',
                'code' => 'EXISTING',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-department', [
                'department_id' => $department->id,
            ])
            ->assertForbidden();
    }

    public function test_organizer_cannot_manage_departments_in_another_organization(): void
    {
        [, $actor] = $this->organizationWithOrganizer('organizer');
        $otherOrganization = Organization::factory()->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/create-department', [
                'organization_id' => $otherOrganization->id,
                'name' => 'Cross Org',
                'code' => 'CROSS',
            ])
            ->assertForbidden();
    }

    public function test_duplicate_department_code_is_rejected(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');
        Department::factory()->for($organization)->create(['code' => 'RANGERS']);

        $this->actingAs($actor)
            ->postJson('/api/commands/create-department', [
                'organization_id' => $organization->id,
                'name' => 'Rangers Two',
                'code' => 'RANGERS',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_archive_and_restore_are_idempotent_guards(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');
        $department = Department::factory()->for($organization)->create();

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-department', [
                'department_id' => $department->id,
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->postJson('/api/commands/archive-department', [
                'department_id' => $department->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Department is already archived.');

        $this->actingAs($actor)
            ->postJson('/api/commands/restore-department', [
                'department_id' => $department->id,
            ])
            ->assertOk();

        $this->actingAs($actor)
            ->postJson('/api/commands/restore-department', [
                'department_id' => $department->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Department is not archived.');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $organization = Organization::factory()->create();

        $this->postJson('/api/commands/create-department', [
            'organization_id' => $organization->id,
            'name' => 'Gate',
            'code' => 'GATE',
        ])->assertUnauthorized();

        $this->getJson("/api/organizations/{$organization->id}/departments")
            ->assertUnauthorized();
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organizationWithOrganizer(string $roleCode): array
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create([
            'name' => 'Organizers',
            'code' => 'ORGANIZERS',
        ]);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $team = Team::query()->where('department_id', $organizersDepartment->id)->where('is_default', true)->first()
            ?? Team::factory()->for($organizersDepartment)->create(['is_default' => true]);

        $staff = Staff::factory()->create();
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        $membership = DepartmentMembership::factory()
            ->for($organizersDepartment)
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

        return [$organization, $user];
    }
}
