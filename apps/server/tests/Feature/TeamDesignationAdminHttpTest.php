<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamDesignation;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Teams\TeamDesignationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team designation administration over the API (M18.12; TEAM-016).
 *
 * Department team designations are department administration: they answer to
 * `department.administer` on the department administration surface.
 * Organization-level designations — the Staff Coordinator team — answer to
 * `organization.designations.manage` on the organization configuration
 * surface. Each path refuses the other's population.
 */
class TeamDesignationAdminHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_department_lead_designates_and_removes_a_department_team(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create(['name' => 'Gate Crew']);
        $lead = $this->userWithRole('department_lead', $department);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/designate-department-team', [
                'department_id' => $department->id,
                'function_code' => TeamDesignation::FUNCTION_LOGISTICS,
                'team_id' => $team->id,
            ])
            ->assertOk()
            ->assertJsonPath('designations.0.function_code', 'logistics')
            ->assertJsonPath('designations.0.team_id', $team->id)
            ->assertJsonPath('designations.0.team_name', 'Gate Crew');

        // The Admin surface's one read carries the designations frame: one row
        // per function, undesignated functions included.
        $this->actingAsClient($lead)
            ->getJson("/api/departments/{$department->id}/teams")
            ->assertOk()
            ->assertJsonCount(count(TeamDesignation::departmentFunctions()), 'designations')
            ->assertJsonPath('designations.0.function_code', 'logistics')
            ->assertJsonPath('designations.0.team_id', $team->id)
            ->assertJsonPath('designations.1.team_id', null);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/remove-department-team-designation', [
                'department_id' => $department->id,
                'function_code' => TeamDesignation::FUNCTION_LOGISTICS,
            ])
            ->assertOk()
            ->assertJsonPath('designations.0.team_id', null);
    }

    public function test_a_department_administration_holder_may_designate_too(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();
        $administrator = $this->userWithRole('department_administration', $department);

        $this->actingAsClient($administrator)
            ->postJson('/api/commands/designate-department-team', [
                'department_id' => $department->id,
                'function_code' => TeamDesignation::FUNCTION_PLANNING,
                'team_id' => $team->id,
            ])
            ->assertOk();
    }

    public function test_department_designation_is_refused_without_department_administer(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();

        // A Logistics holder runs the desk; they do not configure who does.
        $logistics = $this->userWithRole('department_logistics', $department);

        $this->actingAsClient($logistics)
            ->postJson('/api/commands/designate-department-team', [
                'department_id' => $department->id,
                'function_code' => TeamDesignation::FUNCTION_LOGISTICS,
                'team_id' => $team->id,
            ])
            ->assertForbidden();

        // A lead of a different department holds the capability, but not here.
        $otherLead = $this->userWithRole('department_lead', Department::factory()->create());

        $this->actingAsClient($otherLead)
            ->postJson('/api/commands/remove-department-team-designation', [
                'department_id' => $department->id,
                'function_code' => TeamDesignation::FUNCTION_LOGISTICS,
            ])
            ->assertForbidden();
    }

    public function test_a_domain_refusal_is_answered_as_a_422_with_its_reason(): void
    {
        $department = Department::factory()->create();
        $foreignTeam = Team::factory()->create();
        $lead = $this->userWithRole('department_lead', $department);

        $this->actingAsClient($lead)
            ->postJson('/api/commands/designate-department-team', [
                'department_id' => $department->id,
                'function_code' => TeamDesignation::FUNCTION_LOGISTICS,
                'team_id' => $foreignTeam->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A department can only designate its own teams.');
    }

    public function test_an_organizer_reads_designates_and_removes_the_staff_coordinator_team(): void
    {
        [$organization, $organizersDepartment, $organizer] = $this->organizationWithOrganizer();
        $team = Team::factory()->for($organizersDepartment)->create(['name' => 'Intake Desk']);

        $this->actingAsClient($organizer)
            ->getJson("/api/organizations/{$organization->id}/designations")
            ->assertOk()
            ->assertJsonPath('organizers_department.id', $organizersDepartment->id)
            ->assertJsonPath('staff_coordinator', null)
            // The organizer's own team and Intake Desk are both eligible: any
            // active team of the configured Organizers Department is.
            ->assertJsonFragment(['id' => $team->id, 'name' => 'Intake Desk']);

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/designate-staff-coordinator-team', [
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])
            ->assertOk()
            ->assertJsonPath('staff_coordinator.team_id', $team->id)
            ->assertJsonPath('staff_coordinator.team_name', 'Intake Desk');

        $this->actingAsClient($organizer)
            ->postJson('/api/commands/remove-staff-coordinator-team', [
                'organization_id' => $organization->id,
            ])
            ->assertOk()
            ->assertJsonPath('staff_coordinator', null);
    }

    public function test_organization_designations_are_refused_without_the_capability(): void
    {
        [$organization, $organizersDepartment] = $this->organizationWithOrganizer();
        $team = Team::factory()->for($organizersDepartment)->create();

        // A department lead configures their department, not the organization.
        $lead = $this->userWithRole('department_lead', Department::factory()->for($organization)->create());

        $this->actingAsClient($lead)
            ->getJson("/api/organizations/{$organization->id}/designations")
            ->assertForbidden();

        // A designated Staff Coordinator reviews applications; they do not
        // choose who holds the designation (M18.11's restraint, read back).
        $coordinatorTeam = Team::factory()->for($organizersDepartment)->create();
        $coordinatorStaff = Staff::factory()->create();
        $this->addStaffToTeam($coordinatorStaff, $coordinatorTeam);
        app(TeamDesignationService::class)->designateStaffCoordinatorTeam(
            $organization,
            $coordinatorTeam,
            User::factory()->create(),
        );
        $coordinator = User::factory()->create();
        $coordinator->staffProfiles()->attach($coordinatorStaff->id);

        $this->actingAsClient($coordinator)
            ->postJson('/api/commands/designate-staff-coordinator-team', [
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])
            ->assertForbidden();

        // An organizer of a different organization is refused here.
        [, , $foreignOrganizer] = $this->organizationWithOrganizer();

        $this->actingAsClient($foreignOrganizer)
            ->getJson("/api/organizations/{$organization->id}/designations")
            ->assertForbidden();
    }

    /**
     * @return array{0: Organization, 1: Department, 2: User}
     */
    private function organizationWithOrganizer(): array
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create(['name' => 'Organizers']);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        return [
            $organization,
            $organizersDepartment,
            $this->userWithRole('organizer', $organizersDepartment),
        ];
    }

    private function userWithRole(string $roleCode, Department $department): User
    {
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => PermissionRole::query()->where('code', $roleCode)->firstOrFail()->id,
        ]);

        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return $user;
    }

    private function addStaffToTeam(Staff $staff, Team $team): void
    {
        $membership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $membership->id,
            'membership_role' => 'member',
        ]);
    }
}
