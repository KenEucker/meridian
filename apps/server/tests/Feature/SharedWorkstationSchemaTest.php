<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedWorkstationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_workstations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shared_workstations'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'id'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'device_id'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'event_id'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'name'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'trusted'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'created_at'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('shared_workstations', 'revoked_at'));
    }

    public function test_shared_workstation_login_codes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shared_workstation_login_codes'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'id'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'user_id'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'event_id'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'shared_workstation_id'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'code_hash'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'expires_at'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'generated_by_user_id'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'used_at'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'revoked_at'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'created_at'));
        $this->assertTrue(Schema::hasColumn('shared_workstation_login_codes', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('shared_workstation_login_codes', 'code'));
        $this->assertFalse(Schema::hasColumn('shared_workstation_login_codes', 'raw_code'));
    }

    public function test_shared_workstation_and_login_code_ids_are_uuids(): void
    {
        $workstation = SharedWorkstation::factory()->create();
        $loginCode = SharedWorkstationLoginCode::factory()
            ->forSharedWorkstation($workstation)
            ->create();

        $this->assertTrue(Str::isUuid($workstation->id));
        $this->assertTrue(Str::isUuid($loginCode->id));
        $this->assertTrue(Str::isUuid($workstation->event_id));
        $this->assertSame($workstation->event_id, $loginCode->event_id);
        $this->assertSame($workstation->id, $loginCode->shared_workstation_id);
    }

    public function test_shared_workstation_belongs_to_device_and_has_login_codes(): void
    {
        $device = Device::factory()->create([
            'platform' => 'electron',
        ]);
        $workstation = SharedWorkstation::factory()->for($device)->create();
        $firstLoginCode = SharedWorkstationLoginCode::factory()
            ->forSharedWorkstation($workstation)
            ->create();
        $secondLoginCode = SharedWorkstationLoginCode::factory()
            ->forSharedWorkstation($workstation)
            ->create();

        $device->refresh()->load('sharedWorkstations');
        $workstation->refresh()->load('device', 'loginCodes');

        $this->assertTrue($workstation->device->is($device));
        $this->assertCount(1, $device->sharedWorkstations);
        $this->assertTrue($device->sharedWorkstations->first()->is($workstation));
        $this->assertCount(2, $workstation->loginCodes);
        $this->assertTrue($workstation->loginCodes->contains($firstLoginCode));
        $this->assertTrue($workstation->loginCodes->contains($secondLoginCode));
    }

    public function test_login_code_belongs_to_user_generator_and_shared_workstation(): void
    {
        $user = User::factory()->create();
        $generator = User::factory()->create();
        $workstation = SharedWorkstation::factory()->create();

        $loginCode = SharedWorkstationLoginCode::factory()
            ->for($user)
            ->for($generator, 'generatedByUser')
            ->forSharedWorkstation($workstation)
            ->create();

        $user->refresh()->load('sharedWorkstationLoginCodes');
        $generator->refresh()->load('generatedSharedWorkstationLoginCodes');

        $this->assertTrue($loginCode->user->is($user));
        $this->assertTrue($loginCode->generatedByUser->is($generator));
        $this->assertTrue($loginCode->sharedWorkstation->is($workstation));
        $this->assertTrue($user->sharedWorkstationLoginCodes->first()->is($loginCode));
        $this->assertTrue($generator->generatedSharedWorkstationLoginCodes->first()->is($loginCode));
    }

    public function test_shared_workstation_is_unique_per_event_name_and_device(): void
    {
        $eventId = fake()->uuid();
        $device = Device::factory()->create([
            'platform' => 'electron',
        ]);

        SharedWorkstation::factory()->for($device)->create([
            'event_id' => $eventId,
            'name' => 'onsite-command-1',
        ]);

        $this->expectException(QueryException::class);

        SharedWorkstation::factory()->for($device)->create([
            'event_id' => $eventId,
            'name' => 'onsite-command-2',
        ]);
    }

    public function test_trusted_scope_excludes_untrusted_revoked_and_revoked_device_workstations(): void
    {
        $trustedWorkstation = SharedWorkstation::factory()->create();

        SharedWorkstation::factory()->untrusted()->create();
        SharedWorkstation::factory()->revoked()->create();
        SharedWorkstation::factory()
            ->for(Device::factory()->revoked())
            ->create();

        $trustedWorkstations = SharedWorkstation::query()->trusted()->get();

        $this->assertCount(1, $trustedWorkstations);
        $this->assertTrue($trustedWorkstations->first()->is($trustedWorkstation));
        $this->assertTrue($trustedWorkstation->isTrusted());
    }

    public function test_login_code_window_defaults_to_six_weeks(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

        try {
            $loginCode = SharedWorkstationLoginCode::factory()->create();

            $this->assertSame(6, SharedWorkstationLoginCode::VALID_DURATION_WEEKS);
            $this->assertSame(
                now()->addWeeks(6)->toDateTimeString(),
                $loginCode->expires_at->toDateTimeString()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_active_login_code_scope_excludes_expired_used_revoked_and_untrusted_workstations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

        try {
            $activeLoginCode = SharedWorkstationLoginCode::factory()->create();

            SharedWorkstationLoginCode::factory()->expired()->create();
            SharedWorkstationLoginCode::factory()->used()->create();
            SharedWorkstationLoginCode::factory()->revoked()->create();
            SharedWorkstationLoginCode::factory()
                ->forSharedWorkstation(SharedWorkstation::factory()->untrusted()->create())
                ->create();
            SharedWorkstationLoginCode::factory()
                ->forSharedWorkstation(SharedWorkstation::factory()->revoked()->create())
                ->create();
            SharedWorkstationLoginCode::factory()
                ->forSharedWorkstation(
                    SharedWorkstation::factory()
                        ->for(Device::factory()->revoked())
                        ->create()
                )
                ->create();

            $activeLoginCodes = SharedWorkstationLoginCode::query()->active()->get();

            $this->assertCount(1, $activeLoginCodes);
            $this->assertTrue($activeLoginCodes->first()->is($activeLoginCode));
            $this->assertTrue($activeLoginCode->isActive());
        } finally {
            Carbon::setTestNow();
        }
    }
}
