<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeamDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_teams_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('teams'));
        $this->assertTrue(Schema::hasColumn('teams', 'id'));
        $this->assertTrue(Schema::hasColumn('teams', 'department_id'));
        $this->assertTrue(Schema::hasColumn('teams', 'name'));
        $this->assertTrue(Schema::hasColumn('teams', 'code'));
        $this->assertTrue(Schema::hasColumn('teams', 'description'));
        $this->assertTrue(Schema::hasColumn('teams', 'is_default'));
        $this->assertTrue(Schema::hasColumn('teams', 'created_at'));
        $this->assertTrue(Schema::hasColumn('teams', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('teams', 'archived_at'));
    }

    public function test_team_belongs_to_department_and_department_has_many_teams(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->for($department)->create([
            'code' => 'LOGISTICS',
        ]);

        $department->refresh()->load('teams');
        $team->refresh()->load('department');

        $this->assertTrue($team->department->is($department));
        $this->assertCount(2, $department->teams);
        $this->assertTrue($department->teams->contains($team));
    }

    public function test_team_codes_are_unique_within_a_department(): void
    {
        $firstDepartment = Department::factory()->create();
        $secondDepartment = Department::factory()->create();

        Team::factory()->for($firstDepartment)->create([
            'code' => 'OPERATORS',
        ]);

        $otherDepartmentTeam = Team::factory()->for($secondDepartment)->create([
            'code' => 'OPERATORS',
        ]);

        $this->assertTrue($otherDepartmentTeam->department->is($secondDepartment));

        $this->expectException(QueryException::class);

        Team::factory()->for($firstDepartment)->create([
            'code' => 'OPERATORS',
        ]);
    }

    public function test_creating_a_department_creates_and_links_one_default_team(): void
    {
        $department = Department::factory()->create();

        $department->refresh()->load('defaultTeam', 'teams');

        $this->assertNotNull($department->default_team_id);
        $this->assertTrue($department->defaultTeam->department->is($department));
        $this->assertTrue($department->defaultTeam->is_default);
        $this->assertSame('Default', $department->defaultTeam->name);
        $this->assertSame('DEFAULT', $department->defaultTeam->code);
        $this->assertCount(1, $department->teams);
    }

    public function test_departments_may_rename_their_default_team(): void
    {
        $department = Department::factory()->create();
        $defaultTeamId = $department->default_team_id;

        $department->defaultTeam()->firstOrFail()->update([
            'name' => 'Department Leads',
            'code' => 'DEPARTMENT-LEADS',
        ]);

        $department->refresh()->load('defaultTeam');

        $this->assertSame($defaultTeamId, $department->default_team_id);
        $this->assertSame('Department Leads', $department->defaultTeam->name);
        $this->assertSame('DEPARTMENT-LEADS', $department->defaultTeam->code);
        $this->assertTrue($department->defaultTeam->is_default);
    }

    public function test_teams_are_persistent_across_events_by_remaining_department_scoped(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->for($organization)->create();
        $team = Team::factory()->for($department)->create();

        $this->assertSame($department->id, $team->department_id);
        $this->assertFalse(Schema::hasColumn('teams', 'event_id'));
    }

    public function test_active_scope_excludes_archived_teams_without_deleting_them(): void
    {
        $activeTeam = Team::factory()->create();
        $archivedTeam = Team::factory()->archived()->create();

        $this->assertTrue(Team::query()->active()->whereKey($activeTeam)->exists());
        $this->assertFalse(Team::query()->active()->whereKey($archivedTeam)->exists());
        $this->assertDatabaseHas('teams', [
            'id' => $archivedTeam->id,
        ]);
        $this->assertTrue($archivedTeam->isArchived());
        $this->assertFalse($activeTeam->isArchived());
    }
}
