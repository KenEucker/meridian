<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceTrustSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasColumn('devices', 'id'));
        $this->assertTrue(Schema::hasColumn('devices', 'device_label'));
        $this->assertTrue(Schema::hasColumn('devices', 'platform'));
        $this->assertTrue(Schema::hasColumn('devices', 'device_public_key'));
        $this->assertTrue(Schema::hasColumn('devices', 'first_seen_at'));
        $this->assertTrue(Schema::hasColumn('devices', 'last_seen_at'));
        $this->assertTrue(Schema::hasColumn('devices', 'revoked_at'));
        $this->assertTrue(Schema::hasColumn('devices', 'created_at'));
        $this->assertTrue(Schema::hasColumn('devices', 'updated_at'));
    }

    public function test_device_trusts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('device_trusts'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'id'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'user_id'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'device_id'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'trusted_node_fingerprint'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'first_trusted_at'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'last_seen_at'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'expires_at'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'revoked_at'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'created_at'));
        $this->assertTrue(Schema::hasColumn('device_trusts', 'updated_at'));
    }

    public function test_device_and_trust_ids_are_uuids(): void
    {
        $device = Device::factory()->create();
        $trust = DeviceTrust::factory()->for($device)->create();

        $this->assertTrue(Str::isUuid($device->id));
        $this->assertTrue(Str::isUuid($trust->id));
        $this->assertSame($device->id, $trust->device_id);
    }

    public function test_user_and_device_relationships_support_multiple_users_per_device(): void
    {
        $device = Device::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $firstTrust = DeviceTrust::factory()->for($device)->for($firstUser)->create();
        $secondTrust = DeviceTrust::factory()->for($device)->for($secondUser)->create();

        $device->refresh()->load('trusts', 'trustedUsers');
        $firstUser->refresh()->load('deviceTrusts', 'trustedDevices');

        $this->assertCount(2, $device->trusts);
        $this->assertTrue($device->trusts->contains($firstTrust));
        $this->assertTrue($device->trusts->contains($secondTrust));
        $this->assertTrue($device->trustedUsers->contains($firstUser));
        $this->assertTrue($device->trustedUsers->contains($secondUser));
        $this->assertCount(1, $firstUser->deviceTrusts);
        $this->assertTrue($firstUser->deviceTrusts->first()->is($firstTrust));
        $this->assertTrue($firstUser->trustedDevices->first()->is($device));
        $this->assertTrue($firstTrust->user->is($firstUser));
        $this->assertTrue($firstTrust->device->is($device));
    }

    public function test_device_trust_is_unique_per_user_device_pair(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create();

        DeviceTrust::factory()->for($device)->for($user)->create();

        $this->expectException(QueryException::class);

        DeviceTrust::factory()->for($device)->for($user)->create();
    }

    public function test_trust_window_defaults_to_six_weeks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

        try {
            $trust = DeviceTrust::factory()->create();

            $this->assertSame(6, DeviceTrust::TRUST_DURATION_WEEKS);
            $this->assertSame(
                now()->addWeeks(6)->toDateTimeString(),
                $trust->expires_at->toDateTimeString()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_active_scope_excludes_expired_or_revoked_trusts_and_revoked_devices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

        try {
            $activeTrust = DeviceTrust::factory()->create();

            DeviceTrust::factory()->expired()->create();
            DeviceTrust::factory()->revoked()->create();
            DeviceTrust::factory()
                ->for(Device::factory()->revoked())
                ->create();

            $activeTrusts = DeviceTrust::query()->active()->get();

            $this->assertCount(1, $activeTrusts);
            $this->assertTrue($activeTrusts->first()->is($activeTrust));
            $this->assertTrue($activeTrust->isActive());
        } finally {
            Carbon::setTestNow();
        }
    }
}
