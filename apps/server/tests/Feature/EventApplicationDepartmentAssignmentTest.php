<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventApplication;
use App\Models\EventDepartmentAssignment;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Application\DepartmentAssignmentAccess;
use App\Services\Application\DepartmentAssignmentException;
use App\Services\Application\EventApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApplicationDepartmentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_assign_approved_applicant_to_participating_department(): void
    {
        [$application, $department, $assigner] = $this->approvedApplicationScenario();

        $membership = app(EventApplicationService::class)->assignToDepartment(
            $application,
            $department,
            $assigner,
        );

        $staff = Staff::query()->findOrFail($application->staff_id);

        $this->assertSame($department->id, $membership->department_id);
        $this->assertSame($staff->id, $membership->staff_id);
        $this->assertSame(DepartmentMembership::STATUS_ACTIVE, $membership->status);
        $this->assertCount(1, $membership->teamMemberships);
        $this->assertSame($department->default_team_id, $membership->teamMemberships->first()->team_id);

        $organizationStatus = StaffOrganizationStatus::query()
            ->where('organization_id', $application->organization_id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $this->assertSame(StaffOrganizationStatus::STATUS_ACTIVE, $organizationStatus->status);
        $this->assertSame($assigner->id, $organizationStatus->status_changed_by_user_id);

        $audit = AuditEvent::query()
            ->where('action', 'department_membership.assigned_from_application')
            ->where('entity_id', $membership->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame($application->organization_id, $audit->organization_id);
        $this->assertSame($application->event_id, $audit->event_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame($assigner->id, $audit->actor_user_id);
    }

    public function test_department_lead_can_assign_approved_applicant_to_department_they_lead(): void
    {
        [$application, $department] = $this->approvedApplicationScenario(returnAssigner: false);
        $departmentLead = $this->departmentLeadUserFor($department);

        $membership = app(EventApplicationService::class)->assignToDepartment(
            $application,
            $department,
            $departmentLead,
        );

        $this->assertSame($department->id, $membership->department_id);
        $this->assertTrue(
            app(DepartmentAssignmentAccess::class)->canAssignToDepartment($departmentLead, $application, $department),
        );
    }

    public function test_department_lead_cannot_assign_approved_applicant_to_another_department(): void
    {
        [$application, $department] = $this->approvedApplicationScenario(returnAssigner: false);
        $otherDepartment = Department::factory()
            ->for($application->organization)
            ->create(['code' => 'OTHER']);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $application->event_id,
            'department_id' => $otherDepartment->id,
        ]);
        $departmentLead = $this->departmentLeadUserFor($department);

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign this applicant to the selected department.');

        app(EventApplicationService::class)->assignToDepartment(
            $application,
            $otherDepartment,
            $departmentLead,
        );
    }

    public function test_unauthorized_user_cannot_assign_approved_applicant(): void
    {
        [$application, $department] = $this->approvedApplicationScenario(returnAssigner: false);

        $this->expectException(DepartmentAssignmentException::class);

        app(EventApplicationService::class)->assignToDepartment(
            $application,
            $department,
            User::factory()->create(),
        );
    }

    public function test_submitted_application_cannot_be_assigned_to_department(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create();
        $department = Department::factory()->for($organization)->create();
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'status' => EventApplication::STATUS_SUBMITTED,
            'staff_id' => null,
        ]);
        $assigner = $this->applicationAdmin();

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign this applicant to the selected department.');

        app(EventApplicationService::class)->assignToDepartment($application, $department, $assigner);
    }

    public function test_assignment_rejects_archived_department(): void
    {
        [$application, , $assigner] = $this->approvedApplicationScenario();
        $archivedDepartment = Department::factory()
            ->for($application->organization)
            ->archived()
            ->create(['code' => 'ARCHIVED']);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $application->event_id,
            'department_id' => $archivedDepartment->id,
        ]);

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('Archived departments cannot receive new assignments.');

        app(EventApplicationService::class)->assignToDepartment($application, $archivedDepartment, $assigner);
    }

    public function test_assignment_rejects_department_not_participating_in_event(): void
    {
        [$application, , $assigner] = $this->approvedApplicationScenario();
        $nonParticipatingDepartment = Department::factory()
            ->for($application->organization)
            ->create(['code' => 'NOPART']);

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('The selected department must participate in this event.');

        app(EventApplicationService::class)->assignToDepartment($application, $nonParticipatingDepartment, $assigner);
    }

    public function test_assignment_rejects_department_from_another_organization(): void
    {
        [$application, , $assigner] = $this->approvedApplicationScenario();
        $otherOrganization = Organization::factory()->create();
        $otherDepartment = Department::factory()->for($otherOrganization)->create();

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign this applicant to the selected department.');

        app(EventApplicationService::class)->assignToDepartment($application, $otherDepartment, $assigner);
    }

    public function test_assignment_rejects_duplicate_department_membership(): void
    {
        [$application, $department, $assigner] = $this->approvedApplicationScenario();

        app(EventApplicationService::class)->assignToDepartment($application, $department, $assigner);

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('This staff member is already assigned to the selected department.');

        app(EventApplicationService::class)->assignToDepartment($application->refresh(), $department, $assigner);
    }

    public function test_assignment_rejects_do_not_staff_organization_status(): void
    {
        [$application, $department, $assigner] = $this->approvedApplicationScenario();
        $staff = Staff::query()->findOrFail($application->staff_id);

        StaffOrganizationStatus::query()
            ->where('organization_id', $application->organization_id)
            ->where('staff_id', $staff->id)
            ->update([
                'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
                'status_reason' => 'Blocked by organizer.',
            ]);

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('Do Not Staff records cannot be assigned to departments.');

        app(EventApplicationService::class)->assignToDepartment($application->refresh(), $department, $assigner);
    }

    public function test_organizer_assignment_access_allows_same_organization_departments(): void
    {
        [$application, $department, $assigner] = $this->approvedApplicationScenario(returnAssigner: true);

        $this->assertTrue(
            app(DepartmentAssignmentAccess::class)->canAssignToDepartment($assigner, $application, $department),
        );

        $nonParticipatingDepartment = Department::factory()
            ->for($application->organization)
            ->create(['code' => 'NOPART']);

        $this->assertTrue(
            app(DepartmentAssignmentAccess::class)->canAssignToDepartment(
                $assigner,
                $application,
                $nonParticipatingDepartment,
            ),
        );

        $this->expectException(DepartmentAssignmentException::class);
        $this->expectExceptionMessage('The selected department must participate in this event.');

        app(EventApplicationService::class)->assignToDepartment(
            $application,
            $nonParticipatingDepartment,
            $assigner,
        );
    }

    /**
     * @return array{0: EventApplication, 1: Department, 2?: User}
     */
    private function approvedApplicationScenario(bool $returnAssigner = true): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Signal Camp 2026']);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        $assigner = $this->applicationAdmin();
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Casey Applicant',
            'applicant_email' => 'casey.applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $approved = app(EventApplicationService::class)->approve($application, $assigner);

        if ($returnAssigner) {
            return [$approved, $department, $assigner];
        }

        return [$approved, $department];
    }

    private function applicationAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.applications' => true,
            ],
        ]);
    }

    private function departmentLeadUserFor(Department $department): User
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);
        $staff = Staff::factory()->create();
        $user->staffProfiles()->attach($staff->id);
        $team = Team::factory()->for($department)->create(['name' => $department->name.' Leads']);
        $departmentMembership = DepartmentMembership::factory()
            ->for($department)
            ->for($staff)
            ->create();
        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role('department_lead')->id,
        ]);

        return $user;
    }

    private function role(string $code): PermissionRole
    {
        return PermissionRole::query()->where('code', $code)->firstOrFail();
    }
}
