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
use App\Services\Application\EventApplicationService;
use App\Services\Membership\DepartmentMembershipService;
use App\Services\Membership\TeamAssignmentAccess;
use App\Services\Membership\TeamAssignmentException;
use App\Services\Membership\TeamMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffTeamAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_lead_can_assign_staff_to_additional_team_in_department(): void
    {
        [$staff, $department, $extraTeam, $departmentLead] = $this->departmentMemberScenario();

        $teamMembership = app(TeamMembershipService::class)->assignStaffToTeam(
            $staff,
            $extraTeam,
            $departmentLead,
        );

        $this->assertSame($extraTeam->id, $teamMembership->team_id);
        $this->assertSame($staff->id, $teamMembership->staff_id);
        $this->assertNull($teamMembership->archived_at);
        $this->assertSame('member', $teamMembership->membership_role);

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $this->assertCount(2, $departmentMembership->teamMemberships()->active()->get());

        $audit = AuditEvent::query()
            ->where('action', 'team_membership.assigned')
            ->where('entity_id', $teamMembership->id)
            ->firstOrFail();

        $this->assertSame(AuditEvent::SOURCE_ORCHID, $audit->source_context);
        $this->assertSame($department->organization_id, $audit->organization_id);
        $this->assertSame($department->id, $audit->department_id);
        $this->assertSame($departmentLead->id, $audit->actor_user_id);
    }

    public function test_department_lead_can_reactivate_archived_team_membership(): void
    {
        [$staff, $department, $extraTeam, $departmentLead] = $this->departmentMemberScenario();

        $departmentMembership = DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('staff_id', $staff->id)
            ->firstOrFail();

        $archivedMembership = TeamMembership::factory()->archived()->create([
            'team_id' => $extraTeam->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
        ]);

        $teamMembership = app(TeamMembershipService::class)->assignStaffToTeam(
            $staff,
            $extraTeam,
            $departmentLead,
        );

        $this->assertSame($archivedMembership->id, $teamMembership->id);
        $this->assertNull($teamMembership->archived_at);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'team_membership.reactivated',
            'entity_id' => $teamMembership->id,
        ]);
    }

    public function test_department_lead_cannot_assign_staff_to_team_in_another_department(): void
    {
        [$staff, $department, , $departmentLead] = $this->departmentMemberScenario();
        $otherDepartment = Department::factory()
            ->for($department->organization)
            ->create(['code' => 'OTHER']);
        $otherTeam = Team::factory()->for($otherDepartment)->create(['code' => 'OTHER-TEAM']);

        $this->expectException(TeamAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign this staff member to the selected team.');

        app(TeamMembershipService::class)->assignStaffToTeam($staff, $otherTeam, $departmentLead);
    }

    public function test_unauthorized_user_cannot_assign_staff_to_team(): void
    {
        [$staff, , $extraTeam] = $this->departmentMemberScenario(returnDepartmentLead: false);

        $this->expectException(TeamAssignmentException::class);

        app(TeamMembershipService::class)->assignStaffToTeam(
            $staff,
            $extraTeam,
            User::factory()->create(),
        );
    }

    public function test_staff_without_department_membership_cannot_be_assigned_to_team(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create(['code' => 'OPERATORS']);
        $staff = Staff::factory()->create();
        $departmentLead = $this->departmentLeadUserFor($department);

        $this->expectException(TeamAssignmentException::class);
        $this->expectExceptionMessage('Staff must belong to the department before team assignment.');

        app(TeamMembershipService::class)->assignStaffToTeam($staff, $team, $departmentLead);
    }

    public function test_assignment_rejects_archived_team(): void
    {
        [$staff, $department, , $departmentLead] = $this->departmentMemberScenario();
        $archivedTeam = Team::factory()->for($department)->archived()->create(['code' => 'ARCHIVED']);

        $this->assertFalse(
            app(TeamAssignmentAccess::class)->canAssignToTeam($departmentLead, $archivedTeam),
        );

        $this->expectException(TeamAssignmentException::class);
        $this->expectExceptionMessage('You are not authorized to assign this staff member to the selected team.');

        app(TeamMembershipService::class)->assignStaffToTeam($staff, $archivedTeam, $departmentLead);
    }

    public function test_assignment_rejects_duplicate_active_team_membership(): void
    {
        [$staff, $department, $extraTeam, $departmentLead] = $this->departmentMemberScenario();

        app(TeamMembershipService::class)->assignStaffToTeam($staff, $extraTeam, $departmentLead);

        $this->expectException(TeamAssignmentException::class);
        $this->expectExceptionMessage('This staff member is already assigned to the selected team.');

        app(TeamMembershipService::class)->assignStaffToTeam($staff->refresh(), $extraTeam, $departmentLead);
    }

    public function test_assignment_rejects_do_not_staff_organization_status(): void
    {
        [$staff, $department, $extraTeam, $departmentLead] = $this->departmentMemberScenario();

        StaffOrganizationStatus::query()->updateOrCreate(
            [
                'organization_id' => $department->organization_id,
                'staff_id' => $staff->id,
            ],
            [
                'status' => StaffOrganizationStatus::STATUS_DO_NOT_STAFF,
                'status_reason' => 'Blocked by organizer.',
                'status_changed_at' => now(),
            ],
        );

        $this->expectException(TeamAssignmentException::class);
        $this->expectExceptionMessage('Do Not Staff records cannot be assigned to teams.');

        app(TeamMembershipService::class)->assignStaffToTeam($staff->refresh(), $extraTeam, $departmentLead);
    }

    public function test_onboarding_path_assigns_default_team_then_additional_team(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->for($organization)->create(['name' => 'Signal Camp 2026']);
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        EventDepartmentAssignment::factory()->create([
            'event_id' => $event->id,
            'department_id' => $department->id,
        ]);
        $extraTeam = Team::factory()->for($department)->create(['code' => 'OPERATORS', 'name' => 'Gate Operators']);
        $organizer = $this->applicationAdmin();
        $departmentLead = $this->departmentLeadUserFor($department);

        $application = EventApplication::factory()->create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'applicant_legal_name' => 'Casey Applicant',
            'applicant_email' => 'casey.applicant@example.org',
            'status' => EventApplication::STATUS_SUBMITTED,
        ]);

        $approved = app(EventApplicationService::class)->approve($application, $organizer);
        $staff = Staff::query()->findOrFail($approved->staff_id);

        $departmentMembership = app(EventApplicationService::class)->assignToDepartment(
            $approved,
            $department,
            $departmentLead,
        );

        $this->assertCount(1, $departmentMembership->teamMemberships);
        $this->assertSame($department->default_team_id, $departmentMembership->teamMemberships->first()->team_id);

        $teamMembership = app(TeamMembershipService::class)->assignStaffToTeam(
            $staff,
            $extraTeam,
            $departmentLead,
        );

        $this->assertSame($extraTeam->id, $teamMembership->team_id);
        $this->assertCount(2, $departmentMembership->refresh()->teamMemberships()->active()->get());
    }

    public function test_department_lead_assignment_access_matches_department_they_lead(): void
    {
        [$staff, $department, $extraTeam, $departmentLead] = $this->departmentMemberScenario();

        $this->assertTrue(
            app(TeamAssignmentAccess::class)->canAssignToTeam($departmentLead, $extraTeam),
        );

        $otherDepartment = Department::factory()
            ->for($department->organization)
            ->create(['code' => 'OTHER']);
        $otherTeam = Team::factory()->for($otherDepartment)->create(['code' => 'OTHER-TEAM']);

        $this->assertFalse(
            app(TeamAssignmentAccess::class)->canAssignToTeam($departmentLead, $otherTeam),
        );

        $this->expectException(TeamAssignmentException::class);

        app(TeamMembershipService::class)->assignStaffToTeam($staff, $otherTeam, $departmentLead);
    }

    /**
     * @return array{0: Staff, 1: Department, 2: Team, 3?: User}
     */
    private function departmentMemberScenario(bool $returnDepartmentLead = true): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create(['name' => 'Gate']);
        $extraTeam = Team::factory()->for($department)->create(['code' => 'OPERATORS', 'name' => 'Gate Operators']);
        $staff = Staff::factory()->create();

        app(DepartmentMembershipService::class)->assignStaffWithDefaultTeam(
            $staff,
            $department,
        );

        $departmentLead = $this->departmentLeadUserFor($department);

        if ($returnDepartmentLead) {
            return [$staff, $department, $extraTeam, $departmentLead];
        }

        return [$staff, $department, $extraTeam];
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
