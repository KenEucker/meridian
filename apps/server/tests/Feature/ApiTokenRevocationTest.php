<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\User;
use App\Orchid\Filters\ApiToken\ApiTokenDeviceFilter;
use App\Orchid\Filters\ApiToken\ApiTokenUserFilter;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\ApiTokenRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

/**
 * God Mode token listing and revocation, by token and by device (M16.2).
 *
 * Source: AUTH-022, AUTH-023; technical spec 11.4; data/API 12.5. Revocation is
 * evaluated at request time, so a revoked token stops authenticating on its next
 * request without the client cooperating.
 */
class ApiTokenRevocationTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    private function issueTokenFor(User $user, Device $device, string $clientName = 'Meridian client'): string
    {
        return app(ApiTokenIssuer::class)->issue($user, $device, $clientName)->plainTextToken;
    }

    /**
     * Probe whether a token still authenticates.
     *
     * `DELETE /api/auth/session` is an `auth:sanctum` route, so it doubles as
     * the probe — and a successful probe revokes the token it used, which is
     * why each probe that matters uses its own.
     */
    private function probeWith(string $token): TestResponse
    {
        // Every HTTP call in one test shares a container, and a resolved guard
        // caches the user it found. A client makes each request against a fresh
        // process, so the guard is forgotten to match.
        //
        // Authenticating an `auth:sanctum` route also makes Sanctum the default
        // guard for the rest of the test, which is why the console assertions
        // below name the `web` guard rather than relying on the default.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.auth.session.destroy'));
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.api-tokens' => true,
            ],
        ]);
    }

    public function test_god_mode_lists_issued_tokens_with_their_user_and_device(): void
    {
        $holder = User::factory()->create(['name' => 'Robin Field', 'email' => 'robin@example.com']);
        $device = Device::factory()->create(['device_label' => 'Ops iPad', 'platform' => 'ios']);

        $this->issueTokenFor($holder, $device, 'Meridian Field');

        $response = $this->actingAs($this->godModeUser())->get(route('platform.api-tokens'));

        $response->assertOk();
        $response->assertSee('API Tokens');
        $response->assertSee('Meridian Field');
        $response->assertSee('Robin Field');
        $response->assertSee('robin@example.com');
        $response->assertSee('Ops iPad (ios)');
        $response->assertSee('Active');
    }

    public function test_god_mode_can_list_tokens_by_user(): void
    {
        $device = Device::factory()->create();

        $mine = User::factory()->create(['name' => 'Robin Field']);
        $theirs = User::factory()->create(['name' => 'Sam Logistics']);

        $this->issueTokenFor($mine, $device, 'Robin phone');
        $this->issueTokenFor($theirs, $device, 'Sam laptop');

        $response = $this->actingAs($this->godModeUser())->get(route('platform.api-tokens', [
            ApiTokenUserFilter::PARAMETER => $mine->getKey(),
        ]));

        $response->assertOk();
        $response->assertSee('Robin phone');
        $response->assertDontSee('Sam laptop');
    }

    public function test_god_mode_can_list_tokens_by_device(): void
    {
        $user = User::factory()->create();

        $kiosk = Device::factory()->create(['device_label' => 'Gate kiosk']);
        $phone = Device::factory()->create(['device_label' => 'Personal phone']);

        $this->issueTokenFor($user, $kiosk, 'Kiosk client');
        $this->issueTokenFor($user, $phone, 'Phone client');

        $response = $this->actingAs($this->godModeUser())->get(route('platform.api-tokens', [
            ApiTokenDeviceFilter::PARAMETER => $kiosk->getKey(),
        ]));

        $response->assertOk();
        $response->assertSee('Kiosk client');
        $response->assertDontSee('Phone client');

        // Revoking every token on a device is offered while looking at exactly
        // the tokens it would end.
        $response->assertSee('Revoke every token on this device');
    }

    public function test_the_list_offers_no_device_wide_revocation_until_a_device_is_chosen(): void
    {
        $this->issueTokenFor(User::factory()->create(), Device::factory()->create());

        $this->actingAs($this->godModeUser())
            ->get(route('platform.api-tokens'))
            ->assertOk()
            ->assertDontSee('Revoke every token on this device');
    }

    public function test_a_god_mode_revoked_token_fails_its_next_request(): void
    {
        $holder = User::factory()->create();
        $device = Device::factory()->create();
        $plaintext = $this->issueTokenFor($holder, $device);

        $this->probeWith($plaintext)->assertOk();

        // The probe above signs the client out, so the working token is a fresh
        // one; what is under test is God Mode ending it, not the client doing so.
        $plaintext = $this->issueTokenFor($holder, $device);
        $token = ApiToken::query()->whereNull('revoked_at')->sole();

        $this->screen('platform.api-tokens')
            ->actingAs($this->godModeUser(), 'web')
            ->withoutFollowingRedirects()
            ->method('revokeToken', ['token' => $token->getKey()])
            ->assertRedirect(route('platform.api-tokens'));

        $this->assertNotNull($token->fresh()->revoked_at);

        $this->probeWith($plaintext)->assertUnauthorized();
    }

    public function test_revoking_a_token_leaves_the_users_other_devices_signed_in(): void
    {
        $holder = User::factory()->create();
        $phone = Device::factory()->create();
        $laptop = Device::factory()->create();

        $phoneToken = $this->issueTokenFor($holder, $phone);
        $laptopToken = $this->issueTokenFor($holder, $laptop);

        $revoked = ApiToken::query()->forDevice((string) $phone->getKey())->sole();

        $this->screen('platform.api-tokens')
            ->actingAs($this->godModeUser(), 'web')
            ->withoutFollowingRedirects()
            ->method('revokeToken', ['token' => $revoked->getKey()]);

        $this->probeWith($phoneToken)->assertUnauthorized();
        $this->probeWith($laptopToken)->assertOk();
    }

    public function test_god_mode_can_revoke_every_token_on_one_device(): void
    {
        $device = Device::factory()->create();
        $other = Device::factory()->create();

        $first = $this->issueTokenFor(User::factory()->create(), $device);
        $second = $this->issueTokenFor(User::factory()->create(), $device);
        $untouched = $this->issueTokenFor(User::factory()->create(), $other);

        $this->screen('platform.api-tokens')
            ->actingAs($this->godModeUser(), 'web')
            ->withoutFollowingRedirects()
            ->method('revokeDeviceTokens', ['device' => $device->getKey()])
            ->assertRedirect(route('platform.api-tokens'));

        $this->assertSame(0, ApiToken::query()->forDevice((string) $device->getKey())->whereNull('revoked_at')->count());

        $this->probeWith($first)->assertUnauthorized();
        $this->probeWith($second)->assertUnauthorized();
        $this->probeWith($untouched)->assertOk();
    }

    /**
     * Data/API 12.5: revoking a device revokes its tokens. The device may be
     * revoked by any path, so the request-time check is what enforces it rather
     * than the console action alone.
     */
    public function test_a_token_bound_to_a_revoked_device_stops_authenticating(): void
    {
        $device = Device::factory()->create();
        $plaintext = $this->issueTokenFor(User::factory()->create(), $device);

        $this->probeWith($plaintext)->assertOk();

        $plaintext = $this->issueTokenFor(User::factory()->create(), $device);
        $device->forceFill(['revoked_at' => now()])->save();

        $this->probeWith($plaintext)->assertUnauthorized();
    }

    public function test_revoking_an_already_revoked_token_changes_nothing(): void
    {
        $device = Device::factory()->create();
        $this->issueTokenFor(User::factory()->create(), $device);

        $token = ApiToken::query()->sole();
        $operator = $this->godModeUser();

        $this->screen('platform.api-tokens')
            ->actingAs($operator, 'web')
            ->withoutFollowingRedirects()
            ->method('revokeToken', ['token' => $token->getKey()]);

        $revokedAt = $token->fresh()->revoked_at;

        $this->travel(5)->minutes();

        $this->screen('platform.api-tokens')
            ->actingAs($operator, 'web')
            ->withoutFollowingRedirects()
            ->method('revokeToken', ['token' => $token->getKey()]);

        $this->assertTrue($revokedAt->equalTo($token->fresh()->revoked_at));
        $this->assertSame(1, AuditEvent::query()->where('action', ApiTokenRevoker::AUDIT_REVOKED)->count());
    }

    public function test_the_token_screen_requires_its_god_mode_permission(): void
    {
        $withoutPermission = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($withoutPermission)
            ->get(route('platform.api-tokens'))
            ->assertForbidden();
    }

    public function test_a_user_without_the_permission_cannot_revoke_a_token(): void
    {
        $device = Device::factory()->create();
        $plaintext = $this->issueTokenFor(User::factory()->create(), $device);
        $token = ApiToken::query()->sole();

        $withoutPermission = User::factory()->create([
            'permissions' => ['platform.index' => true],
        ]);

        $this->screen('platform.api-tokens')
            ->actingAs($withoutPermission, 'web')
            ->withoutFollowingRedirects()
            ->method('revokeToken', ['token' => $token->getKey()])
            ->assertForbidden();

        $this->assertNull($token->fresh()->revoked_at);
        $this->probeWith($plaintext)->assertOk();
    }

    /**
     * AUTH-025: raw token values are never written to logs, audit entries, or
     * exports — and the console is where an export would start.
     */
    public function test_the_console_never_displays_a_token_value(): void
    {
        $plaintext = $this->issueTokenFor(User::factory()->create(), Device::factory()->create());
        $stored = ApiToken::query()->sole();

        $body = $this->actingAs($this->godModeUser())
            ->get(route('platform.api-tokens'))
            ->assertOk()
            ->getContent();

        $this->assertNotFalse($body);
        $this->assertStringNotContainsString($plaintext, $body);
        $this->assertStringNotContainsString(explode('|', $plaintext, 2)[1], $body);
        $this->assertStringNotContainsString($stored->token, $body);
    }
}
