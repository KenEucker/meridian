<?php

namespace Tests\Feature;

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
}
