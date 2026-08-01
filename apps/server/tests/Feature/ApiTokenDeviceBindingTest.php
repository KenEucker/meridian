<?php

namespace Tests\Feature;

use App\Mail\ApiLoginCodeMail;
use App\Models\ApiLoginCode;
use App\Models\ApiToken;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\ApiDeviceResolver;
use App\Services\Auth\ApiLoginException;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Device-bound token issuance (M16.2).
 *
 * Source: AUTH-021; technical spec 11.4; data/API 5.4, 12.1, 12.5. Every issued
 * bearer token is bound to a `devices` record, and a request that cannot supply
 * a resolvable device identity is refused rather than issued an unbound token.
 */
class ApiTokenDeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Capture the plaintext code out of the mail Meridian sends, exactly as a
     * real client obtains it.
     */
    private function requestCodeFor(string $email): string
    {
        Mail::fake();

        $this->postJson(route('api.auth.magic-link.store'), ['email' => $email])
            ->assertStatus(202);

        $code = null;

        Mail::assertSent(ApiLoginCodeMail::class, function (ApiLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        $this->assertIsString($code);

        return $code;
    }

    /**
     * @param  array<string, mixed>|null  $device
     */
    private function verify(string $email, string $code, ?array $device): TestResponse
    {
        $payload = [
            'email' => $email,
            'code' => $code,
        ];

        if ($device !== null) {
            $payload['device'] = $device;
        }

        return $this->postJson(route('api.auth.magic-link.verify'), $payload);
    }

    public function test_an_issued_token_is_bound_to_the_device_it_was_issued_from(): void
    {
        $device = Device::factory()->create(['device_label' => 'Ops iPad']);
        $code = $this->requestCodeFor('bound@example.com');

        $response = $this->verify('bound@example.com', $code, ['id' => (string) $device->getKey()]);

        $response->assertStatus(201);
        $response->assertJsonPath('device.id', (string) $device->getKey());
        $response->assertJsonPath('device.label', 'Ops iPad');

        $token = ApiToken::query()->sole();

        $this->assertSame((string) $device->getKey(), $token->device_id);
        $this->assertTrue($token->device->is($device));
    }

    public function test_signing_in_refreshes_when_the_bound_device_was_last_seen(): void
    {
        $device = Device::factory()->create([
            'first_seen_at' => now()->subWeeks(3),
            'last_seen_at' => now()->subWeeks(3),
        ]);
        $code = $this->requestCodeFor('seen@example.com');

        $this->verify('seen@example.com', $code, ['id' => (string) $device->getKey()])
            ->assertStatus(201);

        $this->assertTrue($device->fresh()->last_seen_at->isToday());
    }

    public function test_a_request_naming_no_device_is_refused_and_issues_no_token(): void
    {
        $code = $this->requestCodeFor('anonymous.device@example.com');

        $this->verify('anonymous.device@example.com', $code, null)
            ->assertStatus(422)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_UNRESOLVABLE);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_device_identifier_that_is_not_an_identifier_is_refused(): void
    {
        $code = $this->requestCodeFor('malformed.device@example.com');

        $this->verify('malformed.device@example.com', $code, ['id' => 'this-device'])
            ->assertStatus(422)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_UNRESOLVABLE);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_an_unknown_device_that_sends_no_registration_details_is_refused(): void
    {
        $code = $this->requestCodeFor('stranger.device@example.com');

        $this->verify('stranger.device@example.com', $code, ['id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_UNRESOLVABLE);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_a_device_seen_for_the_first_time_is_registered_and_bound(): void
    {
        $deviceId = (string) Str::uuid();
        $code = $this->requestCodeFor('first.sight@example.com');

        $this->verify('first.sight@example.com', $code, [
            'id' => $deviceId,
            'label' => 'Pixel 9',
            'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)),
        ])->assertStatus(201);

        $device = Device::query()->sole();

        $this->assertSame($deviceId, (string) $device->getKey());
        $this->assertSame('Pixel 9', $device->device_label);
        $this->assertSame('android', $device->platform);
        $this->assertNotNull($device->first_seen_at);
        $this->assertSame($deviceId, ApiToken::query()->sole()->device_id);
    }

    public function test_a_registered_device_is_reused_rather_than_duplicated(): void
    {
        $deviceId = (string) Str::uuid();

        $registration = [
            'id' => $deviceId,
            'label' => 'Pixel 9',
            'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)),
        ];

        $this->verify('repeat@example.com', $this->requestCodeFor('repeat@example.com'), $registration)
            ->assertStatus(201);

        $this->verify('repeat@example.com', $this->requestCodeFor('repeat@example.com'), ['id' => $deviceId])
            ->assertStatus(201);

        $this->assertDatabaseCount('devices', 1);
        $this->assertSame(2, ApiToken::query()->forDevice($deviceId)->count());
    }

    /**
     * Technical spec 12.4 has a device sign the operations it originates
     * against its registered public key. A device admitted with key material
     * nothing can verify would sign in today and fail closed later, at the
     * point where its signature is what matters.
     */
    public function test_a_device_offering_unusable_key_material_is_not_registered(): void
    {
        $code = $this->requestCodeFor('bad.key@example.com');

        $this->verify('bad.key@example.com', $code, [
            'id' => (string) Str::uuid(),
            'label' => 'Mystery box',
            'platform' => 'browser',
            'public_key' => 'not-really-a-key',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_UNRESOLVABLE);

        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_revoked_device_cannot_be_issued_a_token(): void
    {
        $device = Device::factory()->revoked()->create();
        $code = $this->requestCodeFor('revoked.device@example.com');

        $this->verify('revoked.device@example.com', $code, ['id' => (string) $device->getKey()])
            ->assertStatus(403)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_REVOKED);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * A refusal about the device is correctable by the client, so it must not
     * cost the person the single-use code they were mailed.
     */
    public function test_a_device_refusal_does_not_spend_the_login_code(): void
    {
        $code = $this->requestCodeFor('retry@example.com');

        $this->verify('retry@example.com', $code, null)->assertStatus(422);

        $this->assertNull(ApiLoginCode::query()->sole()->used_at);

        $this->verify('retry@example.com', $code, ['id' => (string) Device::factory()->create()->getKey()])
            ->assertStatus(201);
    }

    /**
     * AUTH-021 is a rule about what may exist, not only about what the login
     * endpoint accepts, so the column that carries the binding refuses an
     * unbound row.
     */
    public function test_an_unbound_token_cannot_be_stored_at_all(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->tokens()->create([
            'name' => 'Unbound client',
            'token' => hash('sha256', 'plaintext'),
            'abilities' => ['*'],
        ]);
    }

    public function test_the_issuer_binds_every_token_it_creates(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create();

        app(ApiTokenIssuer::class)->issue($user, $device, 'Desktop');

        $token = ApiToken::query()->sole();

        $this->assertSame((string) $device->getKey(), $token->device_id);
        $this->assertSame('Desktop', $token->name);
    }

    public function test_the_resolver_bounds_the_key_material_it_will_store(): void
    {
        $this->expectException(ApiLoginException::class);

        app(ApiDeviceResolver::class)->identify([
            'id' => (string) Str::uuid(),
            'label' => 'Firehose',
            'platform' => 'browser',
            'public_key' => str_repeat('A', ApiDeviceResolver::MAX_PUBLIC_KEY_LENGTH + 1),
        ]);
    }

    /**
     * The device is written only once the code proves good, so the login
     * endpoint — which carries no session by design — cannot be used to fill
     * the `devices` table with hardware that never signed in.
     */
    public function test_a_failed_sign_in_registers_no_device(): void
    {
        $this->requestCodeFor('wrong.code@example.com');

        $this->verify('wrong.code@example.com', 'ZZZZZZZZ', [
            'id' => (string) Str::uuid(),
            'label' => 'Never signed in',
            'platform' => 'browser',
            'public_key' => base64_encode(random_bytes(32)),
        ])->assertStatus(401);

        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_failed_sign_in_does_not_mark_a_known_device_as_seen(): void
    {
        $device = Device::factory()->create([
            'first_seen_at' => now()->subWeeks(2),
            'last_seen_at' => now()->subWeeks(2),
        ]);

        $lastSeen = $device->fresh()->last_seen_at;

        $this->requestCodeFor('wrong.code.known@example.com');

        $this->verify('wrong.code.known@example.com', 'ZZZZZZZZ', ['id' => (string) $device->getKey()])
            ->assertStatus(401);

        $this->assertTrue($lastSeen->equalTo($device->fresh()->last_seen_at));
    }
}
