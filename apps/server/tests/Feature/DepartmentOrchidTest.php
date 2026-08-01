<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class DepartmentOrchidTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_orchid_department_list_displays_departments(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Northwood Collective',
        ]);

        Department::factory()->for($organization)->create([
            'name' => 'Rangers',
            'code' => 'RANGERS',
        ]);

        $response = $this->actingAs($this->departmentAdmin())->get(route('platform.departments'));

        $response->assertOk();
        $response->assertSee('Departments');
        $response->assertSee('Rangers');
        $response->assertSee('RANGERS');
        $response->assertSee('Northwood Collective');
    }

    public function test_orchid_department_detail_displays_edit_scaffold(): void
    {
        $department = Department::factory()->create([
            'name' => 'Gate',
            'code' => 'GATE',
        ]);

        $response = $this->actingAs($this->departmentAdmin())
            ->get(route('platform.departments.edit', $department));

        $response->assertOk();
        $response->assertSee('Edit Department');
        $response->assertSee('Gate');
        $response->assertSee('GATE');
        $response->assertSee('meridian-admin.js');
        $response->assertSee('data-meridian-slug-target="department[code]"', false);
        $response->assertSee('data-meridian-slug-format="code"', false);
        $response->assertSee('Save');
        $response->assertSee('Cancel');
    }

    public function test_orchid_department_screen_requires_permission(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.departments'));

        $response->assertForbidden();
    }

    public function test_orchid_department_save_creates_department(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->screen('platform.departments.create')
            ->actingAs($this->departmentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'department' => [
                    'organization_id' => $organization->id,
                    'name' => 'Rangers',
                    'code' => 'RANGERS',
                    'description' => 'Field operations and volunteer support.',
                ],
            ]);

        $response->assertRedirect(route('platform.departments'));

        $this->assertDatabaseHas('departments', [
            'organization_id' => $organization->id,
            'name' => 'Rangers',
            'code' => 'RANGERS',
            'description' => 'Field operations and volunteer support.',
        ]);
    }

    public function test_orchid_department_save_validates_unique_code_within_organization(): void
    {
        $organization = Organization::factory()->create();

        Department::factory()->for($organization)->create([
            'code' => 'RANGERS',
        ]);

        $response = $this->screen('platform.departments.create')
            ->actingAs($this->departmentAdmin())
            ->withoutFollowingRedirects()
            ->method('save', [
                'department' => [
                    'organization_id' => $organization->id,
                    'name' => 'Duplicate Department',
                    'code' => 'RANGERS',
                ],
            ]);

        $response->assertSessionHasErrors('department.code');
    }

    private function departmentAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.departments' => true,
            ],
        ]);
    }
}
