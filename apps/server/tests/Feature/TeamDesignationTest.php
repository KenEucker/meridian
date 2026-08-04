<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PermissionRole;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamDesignation;
use App\Models\TeamGrant;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Permissions\DepartmentOperationalAccess;
use App\Services\Permissions\EffectiveRoleResolver;
use App\Services\Teams\TeamDesignationException;
use App\Services\Teams\TeamDesignationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team designation model (M18.10; TEAM-011 through TEAM-014, TEAM-017).
 */
class TeamDesignationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_logistics_designation_grants_exactly_the_documented_capability_set(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create(['name' => 'Gate Crew']);
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_LOGISTICS,
            $team,
            $this->actor(),
        );

        $roles = (new EffectiveRoleResolver)->resolveForStaff($staff);

        $this->assertSame([PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS], $roles->pluck('roleCode')->all());

        // The designation attaches the existing department_logistics grant and
        // nothing beyond its documented capabilities (requirements 4.8A).
        $this->assertSame([
            PermissionCatalog::PERMISSION_DEPARTMENT_PRESENCE_MANAGE,
            PermissionCatalog::PERMISSION_DEPARTMENT_ATTENDANCE_MANAGE,
            PermissionCatalog::PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE,
        ], PermissionCatalog::rolePermissions()[PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS]);

        $access = new DepartmentOperationalAccess;
        $user = $this->userFor($staff);
        $event = Event::factory()->for($department->organization)->create();

        $this->assertTrue($access->canManagePresence($user, $event, $department));
        $this->assertTrue($access->canManageAttendance($user, $event, $department));
        $this->assertTrue($access->canManageEquipment($user, $event, $department));
        $this->assertFalse($access->canAssignDeployments($user, $event, $department));
        $this->assertFalse($access->canManagePlanning($user, $event, $department));
        $this->assertFalse($access->canManageDepartmentAdministration($user, $event, $department));
    }

    public function test_removing_a_designation_removes_the_derived_authority(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $designation = $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_OPERATIONS,
            $team,
            $this->actor(),
        );

        $this->assertCount(1, (new EffectiveRoleResolver)->resolveForStaff($staff));

        $removed = $this->service()->removeDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_OPERATIONS,
            $this->actor(),
        );

        $this->assertTrue($removed->isRemoved());
        $this->assertNotNull(TeamGrant::query()->find($designation->team_grant_id)->revoked_at);
        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff));
    }

    public function test_redesignating_a_function_moves_the_authority_to_the_new_team(): void
    {
        $department = Department::factory()->create();
        $firstTeam = Team::factory()->for($department)->create();
        $secondTeam = Team::factory()->for($department)->create();
        $firstStaff = Staff::factory()->create();
        $secondStaff = Staff::factory()->create();
        $this->addStaffToTeam($firstStaff, $firstTeam);
        $this->addStaffToTeam($secondStaff, $secondTeam);

        $first = $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_PLANNING,
            $firstTeam,
            $this->actor(),
        );

        $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_PLANNING,
            $secondTeam,
            $this->actor(),
        );

        $this->assertTrue($first->fresh()->isRemoved());
        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($firstStaff));
        $this->assertSame(
            [PermissionCatalog::ROLE_DEPARTMENT_PLANNING],
            (new EffectiveRoleResolver)->resolveForStaff($secondStaff)->pluck('roleCode')->all(),
        );

        // Zero or one team per function (TEAM-012): only the new designation
        // remains active.
        $this->assertSame(1, TeamDesignation::query()
            ->active()
            ->where('department_id', $department->id)
            ->where('function_code', TeamDesignation::FUNCTION_PLANNING)
            ->count());
    }

    public function test_the_same_team_may_hold_more_than_one_designation(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $team, $this->actor());
        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_OPERATIONS, $team, $this->actor());

        $this->assertEqualsCanonicalizing(
            [PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS, PermissionCatalog::ROLE_DEPARTMENT_OPERATIONS],
            (new EffectiveRoleResolver)->resolveForStaff($staff)->pluck('roleCode')->all(),
        );
    }

    public function test_a_team_from_another_department_cannot_be_designated(): void
    {
        $department = Department::factory()->create();
        $otherTeam = Team::factory()->create();

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('A department can only designate its own teams.');

        $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_LOGISTICS,
            $otherTeam,
            $this->actor(),
        );
    }

    public function test_an_archived_team_cannot_be_designated(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->archived()->create();

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('Archived teams cannot be designated.');

        $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_LOGISTICS,
            $team,
            $this->actor(),
        );
    }

    public function test_designating_the_already_designated_team_is_refused(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();

        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $team, $this->actor());

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('This team already carries the designation.');

        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $team, $this->actor());
    }

    public function test_an_unknown_function_is_refused(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('Unknown department function for team designation.');

        $this->service()->designateDepartmentTeam($department, 'dispatch', $team, $this->actor());
    }

    public function test_the_staff_coordinator_function_is_not_a_department_function(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();

        $this->expectException(TeamDesignationException::class);

        $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_STAFF_COORDINATOR,
            $team,
            $this->actor(),
        );
    }

    public function test_removing_an_undesignated_function_is_refused(): void
    {
        $department = Department::factory()->create();

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('This function has no designated team to remove.');

        $this->service()->removeDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $this->actor());
    }

    public function test_removing_a_designation_leaves_a_direct_grant_untouched(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        // TEAM-013: designation is the ordinary path; a direct grant on the
        // same team survives the designation's removal.
        $directGrant = TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS)->id,
        ]);

        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $team, $this->actor());
        $this->service()->removeDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $this->actor());

        $this->assertNull($directGrant->fresh()->revoked_at);
        $this->assertSame(
            [PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS],
            (new EffectiveRoleResolver)->resolveForStaff($staff)->pluck('roleCode')->all(),
        );
    }

    public function test_an_operator_designation_grants_the_department_operator_role_with_no_capabilities_yet(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_OPERATOR, $team, $this->actor());

        $this->assertSame(
            [PermissionCatalog::ROLE_DEPARTMENT_OPERATOR],
            (new EffectiveRoleResolver)->resolveForStaff($staff)->pluck('roleCode')->all(),
        );

        // The Operator capability set is owned by M18.10A; the designation
        // grants the role and the role carries nothing until then.
        $this->assertArrayNotHasKey(
            PermissionCatalog::ROLE_DEPARTMENT_OPERATOR,
            PermissionCatalog::rolePermissions(),
        );
    }

    public function test_a_staff_coordinator_designation_requires_a_configured_organizers_department(): void
    {
        $organization = Organization::factory()->create(['organizers_department_id' => null]);
        $team = Team::factory()->create();

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('Staff Coordinator designation requires the organization to have a configured Organizers Department.');

        $this->service()->designateStaffCoordinatorTeam($organization, $team, $this->actor());
    }

    public function test_a_staff_coordinator_team_must_belong_to_the_organizers_department(): void
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();
        $otherDepartment = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($otherDepartment)->create();

        $this->expectException(TeamDesignationException::class);
        $this->expectExceptionMessage('The Staff Coordinator team must belong to the configured Organizers Department.');

        $this->service()->designateStaffCoordinatorTeam($organization, $team, $this->actor());
    }

    public function test_a_staff_coordinator_designation_grants_the_organization_scoped_role(): void
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();
        $team = Team::factory()->for($organizersDepartment)->create();
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $designation = $this->service()->designateStaffCoordinatorTeam($organization, $team, $this->actor());

        $this->assertNull($designation->department_id);
        $this->assertSame(
            [PermissionCatalog::ROLE_STAFF_COORDINATOR],
            (new EffectiveRoleResolver)->resolveForStaff($staff)->pluck('roleCode')->all(),
        );

        // The designation carries the role's review authority and nothing
        // wider: application review (M18.11; TEAM-014, requirements 4.4) and
        // profile change request review (M18.20A; VOL-019).
        $this->assertSame(
            [
                PermissionCatalog::PERMISSION_ORGANIZATION_APPLICATIONS_REVIEW,
                PermissionCatalog::PERMISSION_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW,
            ],
            PermissionCatalog::rolePermissions()[PermissionCatalog::ROLE_STAFF_COORDINATOR],
        );

        $removed = $this->service()->removeStaffCoordinatorTeam($organization, $this->actor());

        $this->assertTrue($removed->isRemoved());
        $this->assertCount(0, (new EffectiveRoleResolver)->resolveForStaff($staff));
    }

    public function test_the_permission_explanation_names_the_designation_that_granted_the_capability(): void
    {
        // TEAM-018 / M18.12: a permission explanation names the designation
        // that granted the authority where one exists.
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create(['name' => 'Gate Crew']);
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $this->service()->designateDepartmentTeam(
            $department,
            TeamDesignation::FUNCTION_LOGISTICS,
            $team,
            $this->actor(),
        );

        $role = (new EffectiveRoleResolver)->resolveForStaff($staff)->sole();

        $this->assertSame(
            "You have the Department Logistics role because your team, Gate Crew, is the department's designated Logistics team.",
            $role->reason,
        );

        // A direct grant of the same role on the same team keeps the plain
        // membership explanation: it was granted, not designated (TEAM-013).
        $this->service()->removeDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $this->actor());
        TeamGrant::factory()->create([
            'team_id' => $team->id,
            'permission_role_id' => $this->role(PermissionCatalog::ROLE_DEPARTMENT_LOGISTICS)->id,
        ]);

        $direct = (new EffectiveRoleResolver)->resolveForStaff($staff)->sole();

        $this->assertSame(
            'You have the Department Logistics role because you are a member of the Gate Crew team.',
            $direct->reason,
        );
    }

    public function test_the_staff_coordinator_explanation_names_the_organization_designation(): void
    {
        $organization = Organization::factory()->create();
        $organizersDepartment = Department::factory()->for($organization)->create();
        $organization->forceFill(['organizers_department_id' => $organizersDepartment->id])->save();
        $team = Team::factory()->for($organizersDepartment)->create(['name' => 'Intake Desk']);
        $staff = Staff::factory()->create();
        $this->addStaffToTeam($staff, $team);

        $this->service()->designateStaffCoordinatorTeam($organization, $team, $this->actor());

        $role = (new EffectiveRoleResolver)->resolveForStaff($staff)->sole();

        $this->assertSame(
            "You have the Staff Coordinator role because your team, Intake Desk, is the organization's designated Staff Coordinator team.",
            $role->reason,
        );
    }

    public function test_designation_lifecycle_is_audited_with_scope_function_and_team(): void
    {
        $department = Department::factory()->create();
        $firstTeam = Team::factory()->for($department)->create(['name' => 'First Watch']);
        $secondTeam = Team::factory()->for($department)->create(['name' => 'Second Watch']);
        $actor = $this->actor();

        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $firstTeam, $actor);
        $this->service()->designateDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $secondTeam, $actor);
        $this->service()->removeDepartmentTeam($department, TeamDesignation::FUNCTION_LOGISTICS, $actor);

        $audits = AuditEvent::query()
            ->whereIn('action', ['team_designation.created', 'team_designation.changed', 'team_designation.removed'])
            ->get();

        $this->assertEqualsCanonicalizing(
            ['team_designation.created', 'team_designation.changed', 'team_designation.removed'],
            $audits->pluck('action')->all(),
        );

        foreach ($audits as $audit) {
            $this->assertSame((string) $department->organization_id, (string) $audit->organization_id);
            $this->assertSame((string) $department->id, (string) $audit->department_id);
            $this->assertSame((string) $actor->id, (string) $audit->actor_user_id);
            $this->assertSame(TeamDesignation::FUNCTION_LOGISTICS, $audit->after_json['function_code']);
        }

        $created = $audits->firstWhere('action', 'team_designation.created');
        $this->assertSame((string) $firstTeam->id, $created->after_json['team_id']);
        $this->assertSame('First Watch', $created->after_json['team_name']);

        $changed = $audits->firstWhere('action', 'team_designation.changed');
        $this->assertSame((string) $firstTeam->id, $changed->before_json['team_id']);
        $this->assertSame((string) $secondTeam->id, $changed->after_json['team_id']);

        $removed = $audits->firstWhere('action', 'team_designation.removed');
        $this->assertSame((string) $secondTeam->id, $removed->after_json['team_id']);
        $this->assertNotNull($removed->after_json['removed_at']);
    }

    private function service(): TeamDesignationService
    {
        return app(TeamDesignationService::class);
    }

    private function actor(): User
    {
        return User::factory()->create();
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

    private function addStaffToTeam(Staff $staff, Team $team, string $membershipRole = 'member'): void
    {
        $departmentMembership = DepartmentMembership::factory()
            ->for($team->department)
            ->for($staff)
            ->create();

        TeamMembership::factory()->create([
            'team_id' => $team->id,
            'staff_id' => $staff->id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => $membershipRole,
        ]);
    }
}
