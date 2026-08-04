<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Application\ApplicationReviewAccess;
use App\Services\Application\DepartmentAssignmentAccess;
use App\Services\Application\EventApplicationService;
use App\Services\Credential\CredentialRevocationAccess;
use App\Services\Departments\DepartmentAdminAccess;
use App\Services\Departments\DepartmentSelfAdminAccess;
use App\Services\Staffing\OrganizerStaffAccess;
use App\Services\Teams\TeamDesignationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff Coordinator review authority (M18.11; TEAM-014; requirements 4.4;
 * data/API 6.4, 10.7).
 *
 * The role carries application review, approval, rejection, and deferral for
 * the organization that designated its team, and no other organizer
 * governance capability: not departments, not staff status, not credentials.
 */
class StaffCoordinatorReviewPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_staff_coordinator_role_carries_exactly_its_two_review_capabilities(): void
    {
        // Application review (M18.11; TEAM-014) and profile change request
        // review (M18.20A; VOL-019), and no other organizer governance
        // authority — which the rest of this file asserts one power at a time.
        $this->assertSame(
            [
                PermissionCatalog::PERMISSION_ORGANIZATION_APPLICATIONS_REVIEW,
                PermissionCatalog::PERMISSION_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW,
            ],
            PermissionCatalog::rolePermissions()[PermissionCatalog::ROLE_STAFF_COORDINATOR],
        );
    }

    public function test_a_staff_coordinator_reviews_applications_for_their_organization_only(): void
    {
        [$organization, $coordinator] = $this->staffCoordinatorScenario();
        $otherOrganization = Organization::factory()->create();

        $access = app(ApplicationReviewAccess::class);

        $this->assertTrue($access->canReviewApplicationsForOrganization($coordinator, (string) $organization->id));
        $this->assertFalse($access->canReviewApplicationsForOrganization($coordinator, (string) $otherOrganization->id));

        $ownApplication = $this->submittedApplication($organization);
        $otherApplication = $this->submittedApplication($otherOrganization);

        $this->assertTrue($access->canReviewApplication($coordinator, $ownApplication));
        $this->assertTrue($access->canViewApplication($coordinator, $ownApplication));
        $this->assertFalse($access->canReviewApplication($coordinator, $otherApplication));
        $this->assertFalse($access->canViewApplication($coordinator, $otherApplication));

        $visible = $access->scopeVisibleApplications(EventApplication::query(), $coordinator)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id);

        $this->assertTrue($visible->contains((string) $ownApplication->id));
        $this->assertFalse($visible->contains((string) $otherApplication->id));

        // The catalog path does not depend on the Orchid platform permission.
        $this->assertFalse($access->canReviewApplications($coordinator));
    }

    public function test_a_staff_coordinator_can_approve_reject_and_defer(): void
    {
        [$organization, $coordinator] = $this->staffCoordinatorScenario();
        $service = app(EventApplicationService::class);

        $approved = $service->approve($this->submittedApplication($organization), $coordinator);
        $this->assertSame(EventApplication::STATUS_APPROVED, $approved->status);
        $this->assertSame($coordinator->id, $approved->reviewed_by_user_id);

        $rejected = $service->reject($this->submittedApplication($organization), $coordinator);
        $this->assertSame(EventApplication::STATUS_REJECTED, $rejected->status);

        $deferred = $service->defer($this->submittedApplication($organization), $coordinator);
        $this->assertSame(EventApplication::STATUS_DEFERRED, $deferred->status);
    }

    public function test_a_staff_coordinator_can_assign_an_approved_applicant_within_their_organization(): void
    {
        [$organization, $coordinator] = $this->staffCoordinatorScenario();

        $application = $this->submittedApplication($organization);
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $application->event_id,
            'department_id' => $department->id,
        ]);

        $approved = app(EventApplicationService::class)->approve($application, $coordinator);

        $this->assertTrue(app(DepartmentAssignmentAccess::class)
            ->canAssignToDepartment($coordinator, $approved, $department));

        // Another organization's application stays out of reach even where the
        // coordinator could name one of its departments.
        $otherOrganization = Organization::factory()->create();
        $otherApplication = $this->submittedApplication($otherOrganization);
        $otherDepartment = Department::factory()->for($otherOrganization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $otherApplication->event_id,
            'department_id' => $otherDepartment->id,
        ]);
        $otherApproved = app(EventApplicationService::class)
            ->approve($otherApplication, User::factory()->create());

        $this->assertFalse(app(DepartmentAssignmentAccess::class)
            ->canAssignToDepartment($coordinator, $otherApproved, $otherDepartment));
    }

    public function test_organizers_hold_application_review_through_the_same_capability(): void
    {
        foreach ([PermissionCatalog::ROLE_ORGANIZER, PermissionCatalog::ROLE_LEAD_ORGANIZER] as $roleCode) {
            $this->assertTrue(PermissionCatalog::roleHasPermission(
                $roleCode,
                PermissionCatalog::PERMISSION_ORGANIZATION_APPLICATIONS_REVIEW,
            ));
        }

        [$organization, , , $team] = $this->staffCoordinatorScenario();

        $organizerStaff = Staff::factory()->create();
        $this->addStaffToTeam($organizerStaff, $team->fresh());
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role(PermissionCatalog::ROLE_ORGANIZER)->id,
        ]);
        $organizer = $this->userFor($organizerStaff);

        $this->assertTrue(app(ApplicationReviewAccess::class)
            ->canReviewApplicationsForOrganization($organizer, (string) $organization->id));
    }

    public function test_a_department_lead_sees_submitted_interest_without_review_authority(): void
    {
        [$organization] = $this->staffCoordinatorScenario();

        $department = Department::factory()->for($organization)->create();
        $lead = $this->departmentLeadUserFor($department);

        $application = $this->submittedApplication($organization);
        EventApplicationDepartmentInterest::query()->create([
            'event_application_id' => $application->id,
            'department_id' => $department->id,
        ]);

        $access = app(ApplicationReviewAccess::class);

        $this->assertTrue($access->canViewApplication($lead, $application));
        $this->assertFalse($access->canReviewApplication($lead, $application));
    }

    public function test_a_staff_coordinator_cannot_manage_departments_staff_status_or_credentials(): void
    {
        [$organization, $coordinator] = $this->staffCoordinatorScenario();

        $department = Department::factory()->for($organization)->create();
        $event = Event::factory()->for($organization)->create();

        // Departments: neither the organizer surface nor department self-admin.
        $this->assertFalse(app(DepartmentAdminAccess::class)->canManageDepartments($coordinator, $organization));
        $this->assertFalse(app(DepartmentSelfAdminAccess::class)->canAdministerDepartment($coordinator, $department));

        // Staff status: the organizer staff intake and status surface.
        $this->assertFalse(app(OrganizerStaffAccess::class)->canManageStaff($coordinator, $organization));

        // Credentials: revocation stays with organizers and IC leads (CRED-011).
        $this->assertFalse(app(CredentialRevocationAccess::class)->canRevokeCredential($coordinator, $event));

        // And the catalog itself carries none of those capabilities.
        foreach ([
            PermissionCatalog::PERMISSION_ORGANIZATION_DEPARTMENTS_MANAGE,
            PermissionCatalog::PERMISSION_ORGANIZATION_STAFF_MANAGE,
            PermissionCatalog::PERMISSION_DEPARTMENT_ADMINISTER,
            PermissionCatalog::PERMISSION_EVENT_CREDENTIALS_REVOKE,
        ] as $permission) {
            $this->assertFalse(PermissionCatalog::roleHasPermission(
                PermissionCatalog::ROLE_STAFF_COORDINATOR,
                $permission,
            ), "staff_coordinator must not carry {$permission}.");
        }
    }

    /**
     * An organization with a configured Organizers Department, a designated
     * Staff Coordinator team, and a user on that team.
     *
     * @return array{0: Organization, 1: User, 2: Staff, 3: Team}
     */
    private function staffCoordinatorScenario(): array
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create(['name' => 'Organizers']);
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();

        $team = Team::factory()->for($organizersDepartment)->create(['name' => 'Intake Desk']);
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        app(TeamDesignationService::class)->designateStaffCoordinatorTeam(
            $organization,
            $team,
            User::factory()->create(),
        );

        return [$organization, $this->userFor($staff), $staff, $team];
    }

    private function submittedApplication(Organization $organization): EventApplication
    {
        $event = Event::factory()->for($organization)->create();

        return EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $staff = Staff::factory()->create();
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $this->addStaffToTeam($staff, $team);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role(PermissionCatalog::ROLE_DEPARTMENT_LEAD)->id,
        ]);

        return $this->userFor($staff);
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }

    private function userFor(Staff $staff): User
    {
        $user = User::factory()->create();
        $user->staffProfiles()->attach($staff->id);

        return $user;
    }

    private function addStaffToTeam(Staff $staff, Team $team): void
    {
        $departmentMembership = DepartmentMembership::query()
            ->where('department_id', $team->department_id)
            ->where('staff_id', $staff->id)
            ->first();

        $departmentMembership ??= DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => 'member',
        ]);
    }
}
