<?php

namespace Tests\Feature;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The God Mode Permission Catalog screen.
 *
 * Meridian's permission model (technical spec 15.1, 15.2, 16.2) had no console
 * surface: the only role list in the console was the administrative
 * framework's own, which governs console access and nothing else, and which is
 * empty in this deployment because console access is granted per user. An
 * operator looking for Meridian's roles found an empty table and no indication
 * that they were looking at the wrong thing.
 */
class ConsolePermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_lists_every_effective_role_with_its_scope(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();

        foreach (PermissionCatalog::roles() as $code => $role) {
            $response->assertSee($role['name']);
            $response->assertSee($code);
        }

        // Scope is the column that decides what a role code actually means.
        foreach (['Node', 'Organization', 'Event', 'Department', 'Team'] as $scope) {
            $response->assertSee($scope);
        }
    }

    public function test_the_screen_lists_every_registered_capability_and_what_carries_it(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();

        foreach (PermissionCatalog::permissions() as $code => $description) {
            $response->assertSee($code);
            $response->assertSee($description);
        }
    }

    public function test_the_screen_separates_the_meridian_catalog_from_console_access_roles(): void
    {
        // The whole point of the screen is that these are two different things.
        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();
        $response->assertSee('This is not the same as Roles under Access Controls.', false);
        $response->assertSee('controls who may open this console', false);
    }

    public function test_the_screen_reports_an_all_clear_when_the_stored_catalog_matches_the_build(): void
    {
        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();
        $response->assertSee('The stored catalog matches what this build defines.', false);
    }

    public function test_a_role_missing_from_the_deployment_is_reported_as_drift(): void
    {
        PermissionRole::query()->where('code', PermissionCatalog::ROLE_IC_LEAD)->delete();

        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();
        $response->assertSee('Defined by this build but not stored in this deployment.', false);
        $response->assertSee(PermissionCatalog::ROLE_IC_LEAD);
    }

    public function test_a_capability_the_build_does_not_define_is_reported_as_drift(): void
    {
        Permission::query()->create([
            'code' => 'incidents.invent',
            'description' => 'A capability no build defines.',
        ]);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();
        $response->assertSee('Stored in this deployment but not defined by this build.', false);
        $response->assertSee('incidents.invent');
    }

    public function test_a_role_whose_capabilities_differ_from_the_build_is_reported_as_drift(): void
    {
        // The dangerous case: nothing is missing, the node works, and it
        // enforces permissions differently from every other node on this build.
        $role = PermissionRole::query()->where('code', PermissionCatalog::ROLE_IC_VIEWER)->firstOrFail();
        $permission = Permission::query()
            ->where('code', PermissionCatalog::PERMISSION_INCIDENTS_CLOSE)
            ->firstOrFail();

        DB::table('role_permissions')->insert([
            'id' => (string) Str::orderedUuid(),
            'permission_role_id' => $role->id,
            'permission_id' => $permission->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->godModeUser())->get(route('platform.permissions'));

        $response->assertOk();
        $response->assertSee('Defined by this build:', false);
        $response->assertSee(PermissionCatalog::ROLE_IC_VIEWER);
    }

    public function test_the_screen_never_writes_to_the_catalog(): void
    {
        // Read-only is the point: the catalog belongs to the build, and an
        // operator who could edit it here would put this node out of step with
        // every other node running the same code.
        $before = [
            'roles' => PermissionRole::query()->count(),
            'permissions' => Permission::query()->count(),
            'mappings' => DB::table('role_permissions')->count(),
        ];

        $this->actingAs($this->godModeUser())
            ->get(route('platform.permissions'))
            ->assertOk();

        $this->assertSame($before, [
            'roles' => PermissionRole::query()->count(),
            'permissions' => Permission::query()->count(),
            'mappings' => DB::table('role_permissions')->count(),
        ]);
    }

    public function test_the_screen_requires_its_god_mode_permission(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.permissions'))
            ->assertForbidden();
    }

    public function test_the_console_navigation_distinguishes_the_two_role_lists(): void
    {
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.permissions' => true,
                'platform.systems.roles' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Permission Catalog');
        $response->assertSee('Console Roles');
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.permissions' => true,
            ],
        ]);
    }
}
