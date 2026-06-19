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
use App\Services\Application\ApplicationRescindException;
use App\Services\Application\EventApplicationService;
use App\Services\Membership\TeamMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EventApplicationRescindTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_organizer_can_rescind_approved_application_before_department_or_team_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00'));

        [$application, $reviewer] = $this->approvedApplicationScenario();

        $rescinded = app(EventApplicationService::class)->rescind($application, $reviewer);

        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $rescinded->status);
        $this->assertSame('2026-06-19 16:00:00', $rescinded->withdrawn_at?->format('Y-m-d H:i:s'));
        $this->assertSame($reviewer->id, $rescinded->reviewed_by_user_id);
        $this->assertSame('Rescinded before team assignment.', $rescinded->decision_reason);

        $this->assertDatabaseHas('staff_organization_statuses', [
            'organization_id' => $rescinded->organization_id,
            'staff_id' => $rescinded->staff_id,
            'status' => StaffOrganizationStatus::STATUS_INACTIVE,
            'status_changed_by_user_id' => $reviewer->id,
        ]);

        $audit = AuditEvent::query()
            ->where('action', 'event_application.rescinded')
            ->where('entity_id', $rescinded->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame(EventApplication::STATUS_APPROVED, $audit->before_json['status']);
        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $audit->after_json['status']);
        $this->assertSame('Rescinded before team assignment.', $audit->reason);
    }

    public function test_rescind_before_operational_team_assignment_inactivates_default_only_department_membership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 16:30:00'));

        [$application, $reviewer, $department] = $this->approvedApplicationScenario(includeDepartment: true);

        $departmentMembership = app(EventApplicationService::class)->assignToDepartment(
            $application,
            $department,
            $reviewer,
        );

        $this->assertSame(DepartmentMembership::STATUS_ACTIVE, $departmentMembership->status);
        $this->assertSame(
            StaffOrganizationStatus::STATUS_ACTIVE,
            StaffOrganizationStatus::query()
                ->where('organization_id', $application->organization_id)
                ->where('staff_id', $application->staff_id)
                ->value('status'),
        );

        $rescinded = app(EventApplicationService::class)->rescind($application->refresh(), $reviewer);

        $this->assertSame(EventApplication::STATUS_WITHDRAWN, $rescinded->status);
        $this->assertSame(
            StaffOrganizationStatus::STATUS_INACTIVE,
            StaffOrganizationStatus::query()
                ->where('organization_id', $application->organization_id)
                ->where('staff_id', $application->staff_id)
                ->value('status'),
        );

        $this->assertSame(DepartmentMembership::STATUS_INACTIVE, $departmentMembership->refresh()->status);
        $this->assertSame('Rescinded before team assignment.', $departmentMembership->status_reason);
        $this->assertDatabaseHas('team_memberships', [
            'department_membership_id' => $departmentMembership->id,
            'team_id' => $department->default_team_id,
            'staff_id' => $application->staff_id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'department_membership.inactivated_from_application_rescind',
            'entity_id' => $departmentMembership->id,
            'actor_user_id' => $reviewer->id,
        ]);
    }

    public function test_rescind_is_blocked_after_operational_team_assignment(): void
    {
        [$application, $reviewer, $department] = $this->approvedApplicationScenario(includeDepartment: true);
        $extraTeam = Team::factory()->for($department)->create([
            'code' => 'OPERATORS',
            'name' => 'Gate Operators',
        ]);
        $departmentLead = $this->departmentLeadUserFor($department);
        $staff = Staff::query()->findOrFail($application->staff_id);

        app(EventApplicationService::class)->assignToDepartment($application, $department, $reviewer);
        app(TeamMembershipService::class)->assignStaffToTeam($staff, $extraTeam, $departmentLead);

        $this->expectException(ApplicationRescindException::class);
        $this->expectExceptionMessage('Applications cannot be rescinded after team assignment.');

        app(EventApplicationService::class)->rescind($application->refresh(), $reviewer);
    }

    public function test_rescind_is_blocked_after_archived_operational_team_assignment_history(): void
    {
        [$application, $reviewer, $department] = $this->approvedApplicationScenario(includeDepartment: true);
        $extraTeam = Team::factory()->for($department)->create([
            'code' => 'ARCHIVED-OPS',
            'name' => 'Archived Operators',
        ]);
        $departmentMembership = app(EventApplicationService::class)->assignToDepartment($application, $department, $reviewer);

        TeamMembership::factory()->archived()->create([
            'team_id' => $extraTeam->id,
            'staff_id' => $application->staff_id,
            'department_membership_id' => $departmentMembership->id,
        ]);

        $this->expectException(ApplicationRescindException::class);
        $this->expectExceptionMessage('Applications cannot be rescinded after team assignment.');

        app(EventApplicationService::class)->rescind($application->refresh(), $reviewer);
    }

    public function test_non_approved_application_cannot_be_rescinded(): void
    {
        $application = EventApplication::factory()->create([
            'status' => EventApplication::STATUS_SUBMITTED,
            'staff_id' => null,
        ]);

        $this->expectException(ApplicationRescindException::class);
        $this->expectExceptionMessage('Only approved applications with a linked staff profile can be rescinded.');

        app(EventApplicationService::class)->rescind($application, $this->applicationAdmin());
    }

    public function test_blocked_rescind_leaves_application_and_staff_status_unchanged(): void
    {
        [$application, $reviewer, $department] = $this->approvedApplicationScenario(includeDepartment: true);
        $extraTeam = Team::factory()->for($department)->create([
            'code' => 'RADIO',
            'name' => 'Radio Team',
        ]);
        $departmentLead = $this->departmentLeadUserFor($department);
        $staff = Staff::query()->findOrFail($application->staff_id);

        app(EventApplicationService::class)->assignToDepartment($application, $department, $reviewer);
        app(TeamMembershipService::class)->assignStaffToTeam($staff, $extraTeam, $departmentLead);

        try {
            app(EventApplicationService::class)->rescind($application->refresh(), $reviewer);
        } catch (ApplicationRescindException) {
            // Expected denial for assertions below.
        }

        $this->assertSame(EventApplication::STATUS_APPROVED, $application->refresh()->status);
        $this->assertNull($application->withdrawn_at);
        $this->assertSame(
            StaffOrganizationStatus::STATUS_ACTIVE,
            StaffOrganizationStatus::query()
                ->where('organization_id', $application->organization_id)
                ->where('staff_id', $application->staff_id)
                ->value('status'),
        );
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'event_application.rescinded',
            'entity_id' => $application->id,
        ]);
    }

    /**
     * @return array{0: EventApplication, 1: User, 2?: Department}
     */
    private function approvedApplicationScenario(bool $includeDepartment = false): array
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Signal Camp 2026']);
        $reviewer = $this->applicationAdmin();
        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Casey Applicant',
            'applicant_email' => 'casey.applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $approved = app(EventApplicationService::class)->approve($application, $reviewer);

        if (! $includeDepartment) {
            return [$approved, $reviewer];
        }

        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);

        return [$approved, $reviewer, $department];
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
