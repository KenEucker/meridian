<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceTrustPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_and_access_own_active_device_trust(): void
    {
        $user = User::factory()->create();
        $trust = DeviceTrust::factory()->for($user)->create();

        $this->assertTrue($user->can('view', $trust));
        $this->assertTrue($user->can('access', $trust));
    }

    public function test_user_cannot_view_or_access_another_users_device_trust(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $trust = DeviceTrust::factory()->for($owner)->create();

        $this->assertFalse($otherUser->can('view', $trust));
        $this->assertFalse($otherUser->can('access', $trust));
    }

    public function test_owner_cannot_access_expired_or_revoked_device_trusts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

        try {
            $user = User::factory()->create();
            $expiredTrust = DeviceTrust::factory()->for($user)->expired()->create();
            $revokedTrust = DeviceTrust::factory()->for($user)->revoked()->create();

            $this->assertTrue($user->can('view', $expiredTrust));
            $this->assertFalse($user->can('access', $expiredTrust));
            $this->assertTrue($user->can('view', $revokedTrust));
            $this->assertFalse($user->can('access', $revokedTrust));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_owner_cannot_access_trust_for_revoked_device(): void
    {
        $user = User::factory()->create();
        $trust = DeviceTrust::factory()
            ->for($user)
            ->for(Device::factory()->revoked())
            ->create();

        $this->assertTrue($user->can('view', $trust));
        $this->assertFalse($user->can('access', $trust));
    }
}
