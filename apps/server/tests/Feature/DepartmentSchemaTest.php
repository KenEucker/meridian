<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasColumn('departments', 'id'));
        $this->assertTrue(Schema::hasColumn('departments', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('departments', 'name'));
        $this->assertTrue(Schema::hasColumn('departments', 'code'));
        $this->assertTrue(Schema::hasColumn('departments', 'description'));
        $this->assertTrue(Schema::hasColumn('departments', 'default_team_id'));
        $this->assertTrue(Schema::hasColumn('departments', 'created_at'));
        $this->assertTrue(Schema::hasColumn('departments', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('departments', 'archived_at'));
    }

    public function test_department_belongs_to_organization_and_organization_has_many_departments(): void
    {
        $organization = Organization::factory()->create();
        $firstDepartment = Department::factory()->for($organization)->create();
        $secondDepartment = Department::factory()->for($organization)->create();

        $organization->refresh()->load('departments');
        $firstDepartment->refresh()->load('organization');

        $this->assertTrue($firstDepartment->organization->is($organization));
        $this->assertCount(2, $organization->departments);
        $this->assertTrue($organization->departments->contains($firstDepartment));
        $this->assertTrue($organization->departments->contains($secondDepartment));
    }

    public function test_department_codes_are_unique_within_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        Department::factory()->for($organization)->create([
            'code' => 'RANGERS',
        ]);

        $otherDepartment = Department::factory()->for($otherOrganization)->create([
            'code' => 'RANGERS',
        ]);

        $this->assertTrue($otherDepartment->organization->is($otherOrganization));

        $this->expectException(QueryException::class);

        Department::factory()->for($organization)->create([
            'code' => 'RANGERS',
        ]);
    }

    public function test_department_fields_are_persisted(): void
    {
        $department = Department::factory()->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
            'description' => 'Field operations and volunteer support.',
        ]);

        $this->assertSame('Rangers', $department->name);
        $this->assertSame('RANGERS', $department->code);
        $this->assertSame('Field operations and volunteer support.', $department->description);
        $this->assertNotNull($department->default_team_id);
    }

    public function test_active_scope_excludes_archived_departments(): void
    {
        $activeDepartment = Department::factory()->create();

        Department::factory()->archived()->create();

        $this->assertTrue(Department::query()->active()->first()->is($activeDepartment));
        $this->assertFalse($activeDepartment->isArchived());
    }
}
