<?php

namespace Tests\Feature;

use App\Mail\ApiLoginCodeMail;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\User;
use App\Support\LocalFieldFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Signing in trusts the device it was done from (AUTH-021; technical spec 12.1,
 * 12.2, 12.4).
 *
 * Nothing wrote a `device_trusts` row before this. Field Report acceptance,
 * append, and photo upload all read one — each refuses an origin device that is
 * not actively trusted for the submitting user — and the only row that ever
 * existed came from `meridian:seed-local-field-fixture`. Every Field Report a
 * genuinely signed-in person filed was therefore refused with "Field Report
 * origin device is not actively trusted for the submitting user", and the only
 * account on the node that could file one was the fixture's.
 */
class DeviceTrustOnSignInTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $email, string $deviceId): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.magic-link.store'), ['email' => $email])
            ->assertStatus(202);

        $code = null;

        Mail::assertSent(ApiLoginCodeMail::class, function (ApiLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => $email,
            'code' => $code,
            'device' => ['id' => $deviceId],
        ])->assertStatus(201);
    }

    public function test_signing_in_trusts_the_user_and_device_pair_for_six_weeks(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $device = Device::factory()->create();
        $user = User::factory()->create(['email' => 'trusted@example.com']);

        $this->signIn('trusted@example.com', (string) $device->getKey());

        $trust = DeviceTrust::query()->sole();

        $this->assertSame((string) $user->getKey(), (string) $trust->user_id);
        $this->assertSame((string) $device->getKey(), (string) $trust->device_id);
        $this->assertNull($trust->revoked_at);
        // Six weeks, per technical spec 12.2.
        $this->assertTrue($trust->expires_at->equalTo(Carbon::parse('2027-08-15 18:00:00')));
        $this->assertTrue($trust->isActive());

        Carbon::setTestNow();
    }

    /**
     * The window runs from the last sign-in, not the first, so somebody working
     * an event does not lose their device partway through it.
     */
    public function test_signing_in_again_renews_the_window_without_a_second_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-07-04 18:00:00'));

        $device = Device::factory()->create();
        User::factory()->create(['email' => 'renewed@example.com']);

        $this->signIn('renewed@example.com', (string) $device->getKey());
        $firstTrustedAt = DeviceTrust::query()->sole()->first_trusted_at;

        Carbon::setTestNow(Carbon::parse('2027-07-25 09:00:00'));
        $this->signIn('renewed@example.com', (string) $device->getKey());

        $trust = DeviceTrust::query()->sole();

        $this->assertTrue($trust->first_trusted_at->equalTo($firstTrustedAt));
        $this->assertTrue($trust->expires_at->equalTo(Carbon::parse('2027-09-05 09:00:00')));

        Carbon::setTestNow();
    }

    /**
     * A revocation that a sign-in could undo would last exactly until its holder
     * opened the app.
     */
    public function test_a_revoked_trust_is_not_restored_by_signing_in(): void
    {
        $device = Device::factory()->create();
        $user = User::factory()->create(['email' => 'revoked.trust@example.com']);

        $revoked = DeviceTrust::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'trusted_node_fingerprint' => 'unconfigured-node',
            'first_trusted_at' => now()->subWeek(),
            'last_seen_at' => now()->subWeek(),
            'expires_at' => DeviceTrust::expiresAtFrom(now()->subWeek()),
            'revoked_at' => now()->subDay(),
        ]);

        // The token is still issued: revoking a person's trust in a device is not
        // revoking the device, which `ApiDeviceResolver` enforces separately.
        $this->signIn('revoked.trust@example.com', (string) $device->getKey());

        $this->assertNotNull($revoked->fresh()->revoked_at);
        $this->assertFalse($revoked->fresh()->isActive());
        $this->assertSame(1, DeviceTrust::query()->count());
    }

    /**
     * The end-to-end failure a user hit: signed in for real, filing a Field
     * Report, refused as coming from an untrusted device.
     */
    public function test_a_signed_in_user_can_file_a_field_report_from_the_device_they_signed_in_from(): void
    {
        $this->artisan('meridian:seed-local-field-fixture');

        $deviceId = (string) Str::uuid();
        $email = LocalFieldFixture::USER_EMAIL;
        $staffId = LocalFieldFixture::STAFF_ID;

        Mail::fake();
        $this->postJson(route('api.auth.magic-link.store'), ['email' => $email])
            ->assertStatus(202);

        $code = null;
        Mail::assertSent(ApiLoginCodeMail::class, function (ApiLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        // A device this node has never seen, which is what a browser signing in
        // on new hardware is.
        $token = $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => $email,
            'code' => $code,
            'device' => [
                'id' => $deviceId,
                'label' => 'Meridian Field (web)',
                'platform' => 'web',
                'public_key' => base64_encode(random_bytes(32)),
            ],
        ])->assertStatus(201)->json('token');

        $this->postJson('/api/commands/submit-field-report', [
            'id' => (string) Str::uuid(),
            'event_id' => LocalFieldFixture::EVENT_ID,
            'department_id' => null,
            'team_id' => null,
            'staff_id' => $staffId,
            'temporary_local_number' => 'LOCAL-TRUST01',
            'title' => 'Filed from a device I just signed in on',
            'body' => 'Signing in is what trusted this device.',
            'device_submitted_at' => '2027-07-04T13:20:00Z',
            'origin_device_id' => $deviceId,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertCreated();
    }
}
