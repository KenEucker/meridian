<?php

namespace Tests\Feature;

use App\Models\User;
use App\Orchid\PlatformProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrchidAdminRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_orchid_admin_login_route_loads(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
    }

    public function test_orchid_admin_index_redirects_guests_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('platform.login'));
    }

    public function test_orchid_platform_is_configured_under_development_admin_prefix(): void
    {
        $this->assertSame('/admin', config('platform.prefix'));
        $this->assertSame(PlatformProvider::class, config('platform.provider'));
    }

    public function test_authenticated_user_without_main_permission_can_reach_profile_logout_escape_hatch(): void
    {
        $user = User::factory()->create(['permissions' => []]);

        $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertRedirect(route('platform.profile'));

        $this->actingAs($user)
            ->get(route('platform.profile'))
            ->assertOk()
            ->assertSee('Sign out');

        $this->actingAs($user)
            ->post(route('platform.logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
