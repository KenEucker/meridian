<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerStaffHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_add_invite_list_and_assign_department_lead(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');
        $department = Department::factory()->for($organization)->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);

        $create = $this->actingAsClient($actor)
            ->postJson('/api/commands/add-organization-staff', [
                'organization_id' => $organization->id,
                'legal_name' => '  Avery Staff  ',
                'preferred_name' => ' Avery ',
                'handle' => 'avery-radio',
                'email' => 'AVERY@example.test',
                'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
                'department_id' => $department->id,
                'invite' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('legal_name', 'Avery Staff')
            ->assertJsonPath('preferred_name', 'Avery')
            ->assertJsonPath('email', 'avery@example.test')
            ->assertJsonPath('organization_status', StaffOrganizationStatus::STATUS_ACTIVE)
            ->assertJsonPath('invited', true)
            ->assertJsonPath('departments.0.department_id', $department->id)
            ->assertJsonPath('departments.0.is_lead', false);

        $staffId = (string) $create->json('id');

        $this->assertDatabaseHas('staff_organization_statuses', [
            'organization_id' => $organization->id,
            'staff_id' => $staffId,
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            'status_changed_by_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('users', ['email' => 'avery@example.test']);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'staff.created',
            'entity_id' => $staffId,
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'source_context' => AuditEvent::SOURCE_API,
        ]);

        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$organization->id}/staff")
            ->assertOk()
            ->assertJsonPath('organization_id', $organization->id)
            ->assertJsonFragment([
                'id' => $staffId,
                'display_name' => 'Avery',
                'organization_status' => StaffOrganizationStatus::STATUS_ACTIVE,
            ]);

        $lead = $this->actingAsClient($actor)
            ->postJson('/api/commands/select-department-lead', [
                'department_id' => $department->id,
                'staff_id' => $staffId,
            ])
            ->assertOk()
            ->assertJsonPath('staff.lead_department_ids.0', $department->id)
            ->assertJsonPath('team_membership.team_name', 'Department Leads')
            ->assertJsonPath('team_membership.membership_role', 'lead');

        $leadTeamId = (string) $lead->json('team_membership.team_id');

        $this->assertDatabaseHas('team_grants', [
            'team_id' => $leadTeamId,
            'permission_role_id' => PermissionRole::query()->where('code', 'department_lead')->firstOrFail()->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'department_lead.selected',
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'department_id' => $department->id,
        ]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/remove-department-lead', [
                'department_id' => $department->id,
                'staff_id' => $staffId,
            ])
            ->assertOk()
            ->assertJsonPath('staff.lead_department_ids', [])
            ->assertJsonPath('team_membership.archived_at', fn ($value): bool => is_string($value));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'department_lead.removed',
            'actor_user_id' => $actor->id,
            'department_id' => $department->id,
        ]);
    }

    public function test_existing_cross_organization_staff_can_be_added_without_duplicate_profile(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('lead_organizer');
        $staff = Staff::factory()->create(['email' => 'shared@example.test']);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/add-organization-staff', [
                'organization_id' => $organization->id,
                'legal_name' => 'Ignored Name',
                'email' => 'shared@example.test',
                'status' => StaffOrganizationStatus::STATUS_ACTIVE,
            ])
            ->assertCreated()
            ->assertJsonPath('id', $staff->id)
            ->assertJsonPath('organization_status', StaffOrganizationStatus::STATUS_ACTIVE);

        $this->assertSame(1, Staff::query()->where('email', 'shared@example.test')->count());
    }

    public function test_duplicate_organization_staff_is_rejected(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');
        $staff = Staff::factory()->create(['email' => 'known@example.test']);
        StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->active()
            ->create();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/add-organization-staff', [
                'organization_id' => $organization->id,
                'legal_name' => 'Known Staff',
                'email' => 'known@example.test',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This staff member is already in the organization.');
    }

    public function test_non_organizer_cannot_manage_staff_or_department_leads(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->active()
            ->create();
        $actor = User::factory()->create();
        $actorStaff = Staff::factory()->create();
        $actor->staffProfiles()->attach($actorStaff->id);

        $this->actingAsClient($actor)
            ->getJson("/api/organizations/{$organization->id}/staff")
            ->assertForbidden();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/add-organization-staff', [
                'organization_id' => $organization->id,
                'legal_name' => 'Denied',
                'email' => 'denied@example.test',
            ])
            ->assertForbidden();

        $this->actingAsClient($actor)
            ->postJson('/api/commands/select-department-lead', [
                'department_id' => $department->id,
                'staff_id' => $staff->id,
            ])
            ->assertForbidden();
    }

    public function test_dns_staff_cannot_be_selected_as_department_lead(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');
        $department = Department::factory()->for($organization)->create();
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->create(['status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/select-department-lead', [
                'department_id' => $department->id,
                'staff_id' => $staff->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Do Not Staff records cannot be selected as department leads.');
    }

    public function test_ineligible_department_membership_cannot_be_selected_as_department_lead(): void
    {
        [$organization, $actor] = $this->organizationWithOrganizer('organizer');
        $department = Department::factory()->for($organization)->create();
        $staff = Staff::factory()->create();
        StaffOrganizationStatus::factory()
            ->for($organization)
            ->for($staff)
            ->active()
            ->create();
        DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create(['status' => DepartmentMembership::STATUS_INELIGIBLE]);

        $this->actingAsClient($actor)
            ->postJson('/api/commands/select-department-lead', [
                'department_id' => $department->id,
                'staff_id' => $staff->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ineligible department memberships cannot receive staff intake or lead selection.');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/organizations/{$organization->id}/staff")
            ->assertUnauthorized();

        $this->postJson('/api/commands/add-organization-staff', [
            'organization_id' => $organization->id,
            'legal_name' => 'No Session',
            'email' => 'nosession@example.test',
        ])->assertUnauthorized();
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
