<?php

namespace Tests\Feature;

use App\Models\ApiAuthHandoff;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\AuthIdentity;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\ApiLoginException;
use App\Services\Auth\ApiProviderHandoffService;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Google and Discord login completed in a system browser and returned to the
 * requesting application (M16.3).
 *
 * Source: AUTH-020; technical spec 11.4; data/API 5.4. A client application
 * starts a handoff, opens the system browser at the provider, and exchanges the
 * code the browser brings back for a bearer token. The provider exchange itself
 * is performed by the node, never reimplemented in the client.
 *
 * The flow is exercised once per client target — the web client, the mobile Field
 * application, and the desktop application — because what differs between them is
 * where the node returns the browser: an address on the client's own origin for
 * the web client, and a registered custom scheme for the packaged applications.
 */
class ApiProviderHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_RETURN = 'http://127.0.0.1:8000/login/handoff';

    private const MOBILE_RETURN = 'org.meridian.field://auth/handoff';

    private const DESKTOP_RETURN = 'org.meridian.kiosk://auth/handoff';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meridian.oauth.google.client_id', 'google-client-id');
        config()->set('meridian.oauth.google.client_secret', 'google-client-secret');
        config()->set('meridian.oauth.google.redirect_uri', 'http://127.0.0.1:8000/login/google/callback');
        config()->set('meridian.oauth.google.token_url', 'https://oauth2.googleapis.test/token');
        config()->set('meridian.oauth.google.userinfo_url', 'https://openidconnect.googleapis.test/v1/userinfo');

        config()->set('meridian.oauth.discord.client_id', 'discord-client-id');
        config()->set('meridian.oauth.discord.client_secret', 'discord-client-secret');
        config()->set('meridian.oauth.discord.redirect_uri', 'http://127.0.0.1:8000/login/discord/callback');
        config()->set('meridian.oauth.discord.token_url', 'https://discord.test/api/oauth2/token');
        config()->set('meridian.oauth.discord.userinfo_url', 'https://discord.test/api/users/@me');

        config()->set('meridian.api_tokens.provider_handoff.return_targets', [
            ApiAuthHandoff::TARGET_WEB => self::WEB_RETURN,
            ApiAuthHandoff::TARGET_MOBILE => self::MOBILE_RETURN,
            ApiAuthHandoff::TARGET_DESKTOP => self::DESKTOP_RETURN,
        ]);
    }

    /**
     * The PKCE challenge a client derives from the verifier it keeps (RFC 7636).
     */
    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function startHandoff(string $provider, string $clientTarget, string $verifier): TestResponse
    {
        return $this->getJson(route('api.auth.provider.start', [
            'provider' => $provider,
            'client' => $clientTarget,
            'code_challenge' => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
        ]));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function fakeGoogle(array $profile): void
    {
        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.test/v1/userinfo' => Http::response($profile),
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function fakeDiscord(array $profile): void
    {
        Http::fake([
            'https://discord.test/api/oauth2/token' => Http::response(['access_token' => 'discord-access-token']),
            'https://discord.test/api/users/@me' => Http::response($profile),
        ]);
    }

    /**
     * The provider returns the system browser to the node, exactly as it does for
     * an ordinary browser login.
     *
     * @param  array<string, string>  $query
     */
    private function providerCallback(string $provider, array $query): TestResponse
    {
        return $this->get(route('auth.'.$provider.'.callback', $query));
    }

    /**
     * Read a parameter out of the address the browser was sent on to, which is
     * all a client application sees of the return leg.
     */
    private function returnParam(TestResponse $response, string $key): ?string
    {
        $location = (string) $response->headers->get('Location');
        $query = parse_url($location, PHP_URL_QUERY);

        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $parameters);

        $value = $parameters[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A freshly generated device registration payload, as a client sends on the
     * first sign-in from an install (AUTH-021).
     *
     * @return array<string, string>
     */
    private function newDevice(string $label, string $platform): array
    {
        return [
            'id' => (string) Str::uuid(),
            'label' => $label,
            'platform' => $platform,
            'public_key' => base64_encode(random_bytes(32)),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function exchange(string $code, string $verifier, array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.auth.session.store'), [
            'code' => $code,
            'code_verifier' => $verifier,
            ...$overrides,
        ]);
    }

    public function test_the_web_client_completes_google_login_and_receives_a_token(): void
    {
        $verifier = Str::random(64);

        $start = $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_WEB, $verifier)
            ->assertStatus(201);

        $state = $start->json('state');

        $this->assertIsString($state);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $start->json('authorization_url'));
        $this->assertStringContainsString('state='.$state, (string) $start->json('authorization_url'));
        $this->assertSame(self::WEB_RETURN, $start->json('return_url'));

        $this->fakeGoogle([
            'sub' => 'google-web-subject',
            'email' => 'Web.Client@Example.com',
            'email_verified' => true,
            'name' => 'Web Client Person',
        ]);

        $callback = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code'])
            ->assertRedirect();

        $location = (string) $callback->headers->get('Location');
        $this->assertStringStartsWith(self::WEB_RETURN.'?', $location);
        $this->assertSame($state, $this->returnParam($callback, 'state'));

        $exchangeCode = $this->returnParam($callback, 'code');
        $this->assertIsString($exchangeCode);

        $response = $this->exchange($exchangeCode, $verifier, [
            'client_name' => 'Meridian Admin',
            'device' => $this->newDevice('QA browser profile', 'browser'),
        ])->assertStatus(201);

        $response->assertJsonPath('token_type', 'Bearer');
        $response->assertJsonPath('provider', AuthIdentity::PROVIDER_GOOGLE);
        $response->assertJsonPath('user.email', 'web.client@example.com');
        $this->assertIsString($response->json('token'));

        $token = ApiToken::query()->sole();
        $this->assertSame($response->json('device.id'), $token->device_id);
        $this->assertNotNull($token->expires_at);

        $handoff = ApiAuthHandoff::query()->sole();
        $this->assertSame(ApiAuthHandoff::STATUS_COMPLETED, $handoff->status);
        $this->assertSame(ApiAuthHandoff::TARGET_WEB, $handoff->client_target);
    }

    public function test_the_mobile_field_application_completes_google_login_through_its_registered_scheme(): void
    {
        $verifier = Str::random(64);

        $state = $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_MOBILE, $verifier)
            ->assertStatus(201)
            ->assertJsonPath('return_url', self::MOBILE_RETURN)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-mobile-subject',
            'email' => 'field.phone@example.com',
            'email_verified' => true,
        ]);

        $callback = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code'])
            ->assertRedirect();

        // The operating system, not a browser, is what follows this address: it
        // activates the packaged application that registered the scheme.
        $this->assertStringStartsWith(self::MOBILE_RETURN.'?', (string) $callback->headers->get('Location'));

        $response = $this->exchange((string) $this->returnParam($callback, 'code'), $verifier, [
            'client_name' => 'Meridian Field',
            'device' => $this->newDevice('Pixel 9', 'android'),
        ])->assertStatus(201);

        $response->assertJsonPath('user.email', 'field.phone@example.com');
        $response->assertJsonPath('device.platform', 'android');
        $this->assertSame('Meridian Field', ApiToken::query()->sole()->name);
    }

    public function test_the_desktop_application_completes_discord_login_through_its_registered_scheme(): void
    {
        $verifier = Str::random(64);

        $start = $this->startHandoff(AuthIdentity::PROVIDER_DISCORD, ApiAuthHandoff::TARGET_DESKTOP, $verifier)
            ->assertStatus(201)
            ->assertJsonPath('return_url', self::DESKTOP_RETURN);

        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', (string) $start->json('authorization_url'));

        $state = (string) $start->json('state');

        $this->fakeDiscord([
            'id' => 'discord-desktop-subject',
            'email' => 'kiosk.workstation@example.com',
            'verified' => true,
        ]);

        $callback = $this->providerCallback('discord', ['state' => $state, 'code' => 'provider-code'])
            ->assertRedirect();

        $this->assertStringStartsWith(self::DESKTOP_RETURN.'?', (string) $callback->headers->get('Location'));

        $this->exchange((string) $this->returnParam($callback, 'code'), $verifier, [
            'client_name' => 'Meridian Kiosk',
            'device' => $this->newDevice('Command tent workstation', 'electron'),
        ])->assertStatus(201);

        $this->assertDatabaseHas('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => 'discord-desktop-subject',
            'provider_email' => 'kiosk.workstation@example.com',
            'provider_email_verified' => true,
        ]);
    }

    /**
     * The credential a client is waiting on travels through a URL, and on a
     * custom scheme another application on the machine can register the same
     * one. Nothing that would complete a handoff may be recoverable from the
     * node's own records either (AUTH-025 applies the same rule to tokens).
     */
    public function test_neither_the_handoff_state_nor_its_exchange_code_is_stored_as_a_value(): void
    {
        $verifier = Str::random(64);

        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_MOBILE, $verifier)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-hash-subject',
            'email' => 'hashed@example.com',
            'email_verified' => true,
        ]);

        $callback = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);
        $exchangeCode = (string) $this->returnParam($callback, 'code');

        $stored = (string) json_encode(ApiAuthHandoff::query()->sole()->getAttributes());

        $this->assertStringNotContainsString($state, $stored);
        $this->assertStringNotContainsString($exchangeCode, $stored);
        $this->assertStringNotContainsString($verifier, $stored);

        // The challenge is stored, because verifying the verifier against it is
        // the point; it is a digest and reveals nothing usable.
        $this->assertSame($this->challengeFor($verifier), ApiAuthHandoff::query()->sole()->code_challenge);
    }

    /**
     * The browser doing the signing in is not the application receiving the
     * credential, and a session left open there is one nobody signs out of.
     */
    public function test_completing_a_handoff_does_not_sign_the_system_browser_in(): void
    {
        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_DESKTOP, $verifier)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-no-session-subject',
            'email' => 'no.browser.session@example.com',
            'email_verified' => true,
        ]);

        $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['email' => 'no.browser.session@example.com']);
    }

    public function test_an_ordinary_browser_login_still_completes_through_the_same_callback(): void
    {
        $this->fakeGoogle([
            'sub' => 'google-browser-subject',
            'email' => 'browser.login@example.com',
            'email_verified' => true,
        ]);

        $this->withSession(['auth.google_oauth_state' => 'browser-session-state'])
            ->get(route('auth.google.callback', ['state' => 'browser-session-state', 'code' => 'provider-code']))
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('api_auth_handoffs', 0);
    }

    public function test_a_provider_meridian_does_not_support_has_no_handoff_route(): void
    {
        $this->getJson('/api/auth/facebook/start?client=web&code_challenge='.$this->challengeFor('verifier'))
            ->assertStatus(404);

        $this->assertDatabaseCount('api_auth_handoffs', 0);
    }

    public function test_a_client_target_meridian_does_not_return_to_is_refused(): void
    {
        $this->getJson(route('api.auth.provider.start', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'client' => 'television',
            'code_challenge' => $this->challengeFor(Str::random(64)),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('reason', ApiLoginException::REASON_UNKNOWN_CLIENT_TARGET);

        $this->assertDatabaseCount('api_auth_handoffs', 0);
    }

    /**
     * A node that has not configured a return address for a client target has
     * not enabled provider login for it, and says so rather than sending the
     * browser somewhere else.
     */
    public function test_a_client_target_with_no_configured_return_address_is_refused(): void
    {
        config()->set('meridian.api_tokens.provider_handoff.return_targets.mobile', '');

        $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_MOBILE, Str::random(64))
            ->assertStatus(503)
            ->assertJsonPath('reason', ApiLoginException::REASON_CLIENT_TARGET_NOT_CONFIGURED);

        $this->assertDatabaseCount('api_auth_handoffs', 0);
    }

    public function test_an_unconfigured_provider_is_refused_and_records_no_handoff(): void
    {
        config()->set('meridian.oauth.discord.client_id', null);

        $this->startHandoff(AuthIdentity::PROVIDER_DISCORD, ApiAuthHandoff::TARGET_DESKTOP, Str::random(64))
            ->assertStatus(503)
            ->assertJsonPath('reason', ApiLoginException::REASON_PROVIDER_NOT_CONFIGURED);

        $this->assertDatabaseCount('api_auth_handoffs', 0);
    }

    public function test_a_handoff_cannot_be_started_without_a_pkce_challenge(): void
    {
        $this->getJson(route('api.auth.provider.start', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'client' => ApiAuthHandoff::TARGET_WEB,
        ]))->assertStatus(422)->assertJsonValidationErrors('code_challenge');

        $this->getJson(route('api.auth.provider.start', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'client' => ApiAuthHandoff::TARGET_WEB,
            'code_challenge' => 'too-short',
        ]))->assertStatus(422)->assertJsonValidationErrors('code_challenge');

        $this->assertDatabaseCount('api_auth_handoffs', 0);
    }

    /**
     * A plain challenge is no stronger than the exchange code it is meant to
     * protect, so the only accepted method is the one that hashes it.
     */
    public function test_a_handoff_cannot_declare_a_challenge_method_other_than_s256(): void
    {
        $this->getJson(route('api.auth.provider.start', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'client' => ApiAuthHandoff::TARGET_WEB,
            'code_challenge' => $this->challengeFor(Str::random(64)),
            'code_challenge_method' => 'plain',
        ]))->assertStatus(422)->assertJsonValidationErrors('code_challenge_method');
    }

    public function test_a_canceled_provider_consent_returns_the_client_a_reason_and_no_token(): void
    {
        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_MOBILE, $verifier)
            ->json('state');

        Http::fake();

        $callback = $this->providerCallback('google', ['state' => $state, 'error' => 'access_denied'])
            ->assertRedirect();

        $this->assertStringStartsWith(self::MOBILE_RETURN.'?', (string) $callback->headers->get('Location'));
        $this->assertSame(ApiProviderHandoffService::FAILURE_PROVIDER_DENIED, $this->returnParam($callback, 'error'));
        $this->assertNull($this->returnParam($callback, 'code'));
        $this->assertSame($state, $this->returnParam($callback, 'state'));

        $handoff = ApiAuthHandoff::query()->sole();
        $this->assertSame(ApiAuthHandoff::STATUS_FAILED, $handoff->status);
        $this->assertSame(ApiProviderHandoffService::FAILURE_PROVIDER_DENIED, $handoff->failure_reason);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_a_provider_email_that_is_not_verified_fails_the_handoff(): void
    {
        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_WEB, $verifier)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-unverified-subject',
            'email' => 'unverified.handoff@example.com',
            'email_verified' => false,
        ]);

        $callback = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);

        $this->assertSame(ApiProviderHandoffService::FAILURE_UNVERIFIED_EMAIL, $this->returnParam($callback, 'error'));
        $this->assertDatabaseMissing('users', ['email' => 'unverified.handoff@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_disabled_account_fails_the_handoff(): void
    {
        User::factory()->create(['email' => 'disabled.handoff@example.com', 'disabled_at' => now()]);

        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_WEB, $verifier)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-disabled-subject',
            'email' => 'disabled.handoff@example.com',
            'email_verified' => true,
        ]);

        $callback = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);

        $this->assertSame(ApiProviderHandoffService::FAILURE_ACCOUNT_DISABLED, $this->returnParam($callback, 'error'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('auth_identities', ['provider_subject' => 'google-disabled-subject']);
    }

    public function test_a_handoff_that_outlives_its_window_cannot_be_completed(): void
    {
        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_WEB, $verifier)
            ->json('state');

        Http::fake();

        $this->travel(app(ApiProviderHandoffService::class)->expiresMinutes() + 1)->minutes();

        $callback = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);

        $this->assertSame(ApiProviderHandoffService::FAILURE_EXPIRED, $this->returnParam($callback, 'error'));
        $this->assertSame(ApiAuthHandoff::STATUS_FAILED, ApiAuthHandoff::query()->sole()->status);
        Http::assertNothingSent();
    }

    /**
     * A replayed callback must not cancel a sign-in the client has not finished.
     */
    public function test_a_replayed_provider_callback_leaves_a_live_exchange_code_alone(): void
    {
        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_MOBILE, $verifier)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-replay-subject',
            'email' => 'replayed@example.com',
            'email_verified' => true,
        ]);

        $first = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);
        $exchangeCode = (string) $this->returnParam($first, 'code');

        $second = $this->providerCallback('google', ['state' => $state, 'code' => 'provider-code']);

        $this->assertSame(ApiProviderHandoffService::FAILURE_ALREADY_ANSWERED, $this->returnParam($second, 'error'));
        $this->assertSame(ApiAuthHandoff::STATUS_AUTHENTICATED, ApiAuthHandoff::query()->sole()->status);

        $this->exchange($exchangeCode, $verifier, [
            'device' => $this->newDevice('Pixel 9', 'android'),
        ])->assertStatus(201);
    }

    public function test_an_exchange_code_is_single_use(): void
    {
        $verifier = Str::random(64);
        $device = $this->newDevice('Pixel 9', 'android');
        $exchangeCode = $this->completedHandoffCode(
            AuthIdentity::PROVIDER_GOOGLE,
            ApiAuthHandoff::TARGET_MOBILE,
            $verifier,
            'single.use@example.com',
        );

        $this->exchange($exchangeCode, $verifier, ['device' => $device])->assertStatus(201);

        $this->exchange($exchangeCode, $verifier, ['device' => $device])
            ->assertStatus(401)
            ->assertJsonPath('reason', ApiLoginException::REASON_INVALID_HANDOFF);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * Possession of the exchange code is not enough. A malicious application
     * registering the same custom scheme sees the code and no verifier, and
     * refusing without spending the code leaves the real client's sign-in intact
     * rather than letting the interception cancel it.
     */
    public function test_an_exchange_without_the_verifier_that_started_the_handoff_is_refused(): void
    {
        $verifier = Str::random(64);
        $device = $this->newDevice('Pixel 9', 'android');
        $exchangeCode = $this->completedHandoffCode(
            AuthIdentity::PROVIDER_GOOGLE,
            ApiAuthHandoff::TARGET_MOBILE,
            $verifier,
            'intercepted@example.com',
        );

        $this->exchange($exchangeCode, Str::random(64), ['device' => $device])
            ->assertStatus(401)
            ->assertJsonPath('reason', ApiLoginException::REASON_INVALID_CODE_VERIFIER);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(ApiAuthHandoff::STATUS_AUTHENTICATED, ApiAuthHandoff::query()->sole()->status);

        $this->exchange($exchangeCode, $verifier, ['device' => $device])->assertStatus(201);
    }

    public function test_an_exchange_after_the_handoff_window_ends_is_refused(): void
    {
        $verifier = Str::random(64);
        $exchangeCode = $this->completedHandoffCode(
            AuthIdentity::PROVIDER_GOOGLE,
            ApiAuthHandoff::TARGET_WEB,
            $verifier,
            'too.late@example.com',
        );

        $this->travel(app(ApiProviderHandoffService::class)->expiresMinutes() + 1)->minutes();

        $this->exchange($exchangeCode, $verifier, ['device' => $this->newDevice('QA browser profile', 'browser')])
            ->assertStatus(401)
            ->assertJsonPath('reason', ApiLoginException::REASON_INVALID_HANDOFF);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * AUTH-021 holds on this path too, and a device refusal is correctable, so it
     * must not cost the client the sign-in it just completed.
     */
    public function test_an_exchange_naming_no_device_is_refused_without_spending_the_handoff(): void
    {
        $verifier = Str::random(64);
        $exchangeCode = $this->completedHandoffCode(
            AuthIdentity::PROVIDER_GOOGLE,
            ApiAuthHandoff::TARGET_DESKTOP,
            $verifier,
            'no.device@example.com',
        );

        $this->exchange($exchangeCode, $verifier)
            ->assertStatus(422)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_UNRESOLVABLE);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('devices', 0);
        $this->assertSame(ApiAuthHandoff::STATUS_AUTHENTICATED, ApiAuthHandoff::query()->sole()->status);

        $this->exchange($exchangeCode, $verifier, ['device' => $this->newDevice('Command tent workstation', 'electron')])
            ->assertStatus(201);
    }

    public function test_an_exchange_from_a_revoked_device_is_refused(): void
    {
        $verifier = Str::random(64);
        $exchangeCode = $this->completedHandoffCode(
            AuthIdentity::PROVIDER_GOOGLE,
            ApiAuthHandoff::TARGET_MOBILE,
            $verifier,
            'revoked.device.handoff@example.com',
        );

        $device = Device::factory()->revoked()->create();

        $this->exchange($exchangeCode, $verifier, ['device' => ['id' => (string) $device->getKey()]])
            ->assertStatus(403)
            ->assertJsonPath('reason', ApiLoginException::REASON_DEVICE_REVOKED);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * A handoff is scoped to the provider it was started for, so a state that
     * leaks cannot be completed at the other provider's callback.
     */
    public function test_a_handoff_started_at_one_provider_is_not_completed_at_the_other(): void
    {
        $verifier = Str::random(64);
        $state = (string) $this->startHandoff(AuthIdentity::PROVIDER_GOOGLE, ApiAuthHandoff::TARGET_WEB, $verifier)
            ->json('state');

        Http::fake();

        // Not a handoff Discord knows about, so this falls through to the
        // ordinary browser login path, which has no session state to match.
        $this->providerCallback('discord', ['state' => $state, 'code' => 'provider-code'])
            ->assertRedirect(route('login'));

        $this->assertSame(ApiAuthHandoff::STATUS_PENDING, ApiAuthHandoff::query()->sole()->status);
        Http::assertNothingSent();
    }

    /**
     * AUTH-025: the audit entry says a token was issued, which path issued it,
     * and which device holds it, and carries nothing that could be used.
     */
    public function test_a_token_issued_from_a_handoff_is_audited_as_a_provider_handoff(): void
    {
        $verifier = Str::random(64);
        $exchangeCode = $this->completedHandoffCode(
            AuthIdentity::PROVIDER_GOOGLE,
            ApiAuthHandoff::TARGET_MOBILE,
            $verifier,
            'audited.handoff@example.com',
        );

        $response = $this->exchange($exchangeCode, $verifier, [
            'device' => $this->newDevice('Pixel 9', 'android'),
        ])->assertStatus(201);

        $issued = AuditEvent::query()->where('action', ApiTokenIssuer::AUDIT_ISSUED)->sole();

        $this->assertSame(ApiTokenIssuer::REASON_PROVIDER_HANDOFF, $issued->reason);
        $this->assertSame((string) ApiToken::query()->sole()->getKey(), $issued->entity_id);
        $this->assertSame($response->json('device.id'), $issued->after_json['device_id']);

        $auditText = (string) json_encode($issued->getAttributes());
        $this->assertStringNotContainsString((string) $response->json('token'), $auditText);
        $this->assertStringNotContainsString($exchangeCode, $auditText);
        $this->assertStringNotContainsString($verifier, $auditText);
    }

    /**
     * Walk a handoff to the point where a client holds its exchange code, which
     * is where every exchange test starts.
     */
    private function completedHandoffCode(
        string $provider,
        string $clientTarget,
        string $verifier,
        string $email,
    ): string {
        $state = (string) $this->startHandoff($provider, $clientTarget, $verifier)
            ->assertStatus(201)
            ->json('state');

        $this->fakeGoogle([
            'sub' => 'google-subject-'.Str::random(8),
            'email' => $email,
            'email_verified' => true,
        ]);

        $callback = $this->providerCallback($provider, ['state' => $state, 'code' => 'provider-code'])
            ->assertRedirect();

        $code = $this->returnParam($callback, 'code');

        $this->assertIsString($code);

        return $code;
    }
}
