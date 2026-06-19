<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Membership\DepartmentMembershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DepartmentMembershipDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_memberships_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('department_memberships'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'id'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'department_id'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'staff_id'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'status'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'status_reason'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'created_at'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('department_memberships', 'archived_at'));
    }

    public function test_team_memberships_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('team_memberships'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'id'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'team_id'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'staff_id'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'department_membership_id'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'membership_role'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'created_at'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('team_memberships', 'archived_at'));
    }

    public function test_staff_can_belong_to_multiple_departments_and_multiple_teams(): void
    {
        $staff = Staff::factory()->create();
        $firstDepartment = Department::factory()->create();
        $secondDepartment = Department::factory()->create();
        $firstExtraTeam = Team::factory()->for($firstDepartment)->create(['code' => 'OPERATORS']);
        $secondExtraTeam = Team::factory()->for($secondDepartment)->create(['code' => 'LOGISTICS']);
        $service = new DepartmentMembershipService;

        $firstMembership = $service->createWithTeams($staff, $firstDepartment, [
            $firstDepartment->defaultTeam,
            $firstExtraTeam,
        ]);
        $secondMembership = $service->createWithTeams($staff, $secondDepartment, [
            $secondDepartment->defaultTeam,
            $secondExtraTeam,
        ]);

        $staff->refresh()->load('departmentMemberships', 'teamMemberships');

        $this->assertCount(2, $staff->departmentMemberships);
        $this->assertCount(4, $staff->teamMemberships);
        $this->assertTrue($staff->departmentMemberships->contains($firstMembership));
        $this->assertTrue($staff->departmentMemberships->contains($secondMembership));
    }

    public function test_domain_service_requires_at_least_one_team_for_department_membership(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Department membership requires at least one team.');

        (new DepartmentMembershipService)->createWithTeams(
            Staff::factory()->create(),
            Department::factory()->create(),
            [],
        );
    }

    public function test_domain_service_rejects_team_from_another_department(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All team memberships must belong to the assigned department.');

        (new DepartmentMembershipService)->createWithTeams(
            Staff::factory()->create(),
            $department,
            [$otherDepartment->defaultTeam],
        );
    }

    public function test_team_membership_requires_team_from_department_membership_department(): void
    {
        $departmentMembership = DepartmentMembership::factory()->create();
        $otherDepartment = Department::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Team membership must use a team from the same department as the department membership.');

        TeamMembership::factory()->create([
            'department_membership_id' => $departmentMembership->id,
            'staff_id' => $departmentMembership->staff_id,
            'team_id' => $otherDepartment->default_team_id,
        ]);
    }

    public function test_last_active_team_membership_cannot_be_archived_or_deleted(): void
    {
        $department = Department::factory()->create();
        $staff = Staff::factory()->create();
        $extraTeam = Team::factory()->for($department)->create(['code' => 'EXTRA']);
        $departmentMembership = (new DepartmentMembershipService)->createWithTeams($staff, $department, [
            $department->defaultTeam,
            $extraTeam,
        ]);
        $departmentMembership->load('teamMemberships');

        $firstTeamMembership = $departmentMembership->teamMemberships->firstOrFail();
        $firstTeamMembership->update(['archived_at' => now()]);

        $remainingTeamMembership = $departmentMembership->teamMemberships()
            ->whereNull('archived_at')
            ->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Department membership requires at least one active team membership.');

        $remainingTeamMembership->delete();
    }

    public function test_department_membership_is_unique_per_staff_and_department(): void
    {
        $department = Department::factory()->create();
        $staff = Staff::factory()->create();

        DepartmentMembership::factory()->for($department)->for($staff)->create();

        $this->expectException(QueryException::class);

        DepartmentMembership::factory()->for($department)->for($staff)->create();
    }

    public function test_active_scopes_exclude_archived_memberships_without_deleting_them(): void
    {
        $activeDepartmentMembership = DepartmentMembership::factory()->create();
        $archivedDepartmentMembership = DepartmentMembership::factory()->archived()->create();
        $activeTeamMembership = TeamMembership::factory()->create();
        $archivedTeamMembership = TeamMembership::factory()->archived()->create();

        $this->assertTrue(DepartmentMembership::query()->active()->whereKey($activeDepartmentMembership)->exists());
        $this->assertFalse(DepartmentMembership::query()->active()->whereKey($archivedDepartmentMembership)->exists());
        $this->assertTrue(TeamMembership::query()->active()->whereKey($activeTeamMembership)->exists());
        $this->assertFalse(TeamMembership::query()->active()->whereKey($archivedTeamMembership)->exists());
        $this->assertDatabaseHas('department_memberships', ['id' => $archivedDepartmentMembership->id]);
        $this->assertDatabaseHas('team_memberships', ['id' => $archivedTeamMembership->id]);
    }
}
