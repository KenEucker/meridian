<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrchidAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_orchid_admin_command_creates_user_with_uuid_primary_key(): void
    {
        $this->artisan('orchid:admin', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotEmpty($user->permissions);
    }

    public function test_uuid_user_can_query_notifications(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, $user->unreadNotifications()->count());
    }
}
