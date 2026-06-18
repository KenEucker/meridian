<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscordOAuthAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meridian.oauth.discord.client_id', 'discord-client-id');
        config()->set('meridian.oauth.discord.client_secret', 'discord-client-secret');
        config()->set('meridian.oauth.discord.redirect_uri', 'http://127.0.0.1:8000/login/discord/callback');
        config()->set('meridian.oauth.discord.token_url', 'https://discord.com/api/oauth2.test/token');
        config()->set('meridian.oauth.discord.userinfo_url', 'https://discord.com/api/users/@me.test');
        config()->set('meridian.oauth.post_login_redirect', '/home');
    }

    public function test_login_screen_links_to_discord_oauth(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee(route('auth.discord.redirect'));
        $response->assertSee('Continue with Discord');
    }

    public function test_discord_redirect_generates_state_and_authorization_url(): void
    {
        $response = $this->get(route('auth.discord.redirect'));

        $response->assertRedirect();
        $response->assertSessionHas('auth.discord_oauth_state');

        $location = $response->headers->get('Location');

        $this->assertIsString($location);
        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $location);
        $this->assertStringContainsString('client_id=discord-client-id', $location);
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2F127.0.0.1%3A8000%2Flogin%2Fdiscord%2Fcallback', $location);
        $this->assertStringContainsString('response_type=code', $location);
        $this->assertStringContainsString('scope=identify%20email', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_discord_redirect_fails_safely_when_config_is_missing(): void
    {
        config()->set('meridian.oauth.discord.client_id', null);

        $response = $this->get(route('auth.discord.redirect'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $response->assertSessionMissing('auth.discord_oauth_state');
    }

    public function test_discord_callback_creates_user_auth_identity_and_session_for_verified_email(): void
    {
        Http::fake([
            'https://discord.com/api/oauth2.test/token' => Http::response([
                'access_token' => 'discord-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://discord.com/api/users/@me.test' => Http::response([
                'id' => 'discord-subject-123',
                'email' => 'Volunteer@Example.com',
                'verified' => true,
                'username' => 'volunteer',
            ]),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'volunteer@example.com')->first();

        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => 'discord-subject-123',
            'provider_email' => 'volunteer@example.com',
            'provider_email_verified' => true,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://discord.com/api/oauth2.test/token'
                && $request['code'] === 'valid-code'
                && $request['grant_type'] === 'authorization_code';
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.com/api/users/@me.test');
    }

    public function test_discord_callback_links_same_verified_email_to_existing_google_user(): void
    {
        $user = User::factory()->create([
            'email' => 'same.email@example.com',
        ]);

        AuthIdentity::factory()->for($user)->google()->create([
            'provider_subject' => 'google-subject-existing',
            'provider_email' => 'same.email@example.com',
            'provider_email_verified' => true,
        ]);

        Http::fake([
            'https://discord.com/api/oauth2.test/token' => Http::response([
                'access_token' => 'discord-access-token',
            ]),
            'https://discord.com/api/users/@me.test' => Http::response([
                'id' => 'discord-subject-existing',
                'email' => 'Same.Email@Example.com',
                'verified' => true,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        $this->assertSame(1, User::query()->where('email', 'same.email@example.com')->count());
        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-existing',
            'provider_email' => 'same.email@example.com',
            'provider_email_verified' => true,
        ]);
        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => 'discord-subject-existing',
            'provider_email' => 'same.email@example.com',
            'provider_email_verified' => true,
        ]);
    }

    public function test_discord_callback_rejects_unverified_email_without_creating_identity(): void
    {
        Http::fake([
            'https://discord.com/api/oauth2.test/token' => Http::response([
                'access_token' => 'discord-access-token',
            ]),
            'https://discord.com/api/users/@me.test' => Http::response([
                'id' => 'discord-subject-unverified',
                'email' => 'unverified@example.com',
                'verified' => false,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => 'discord-subject-unverified',
        ]);
    }

    public function test_discord_callback_rejects_missing_email_without_creating_identity(): void
    {
        Http::fake([
            'https://discord.com/api/oauth2.test/token' => Http::response([
                'access_token' => 'discord-access-token',
            ]),
            'https://discord.com/api/users/@me.test' => Http::response([
                'id' => 'discord-subject-missing-email',
                'verified' => true,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $this->assertGuest();
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => 'discord-subject-missing-email',
        ]);
    }

    public function test_discord_callback_rejects_state_mismatch_without_provider_requests(): void
    {
        Http::fake();

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'different-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_discord_callback_handles_token_connection_errors_without_exception_page(): void
    {
        Http::fake([
            'https://discord.com/api/oauth2.test/token' => fn () => throw new ConnectionException('cURL error 60'),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $this->assertGuest();
    }

    public function test_discord_callback_handles_userinfo_connection_errors_without_exception_page(): void
    {
        Http::fake([
            'https://discord.com/api/oauth2.test/token' => Http::response([
                'access_token' => 'discord-access-token',
            ]),
            'https://discord.com/api/users/@me.test' => fn () => throw new ConnectionException('cURL error 60'),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_complete_discord_login(): void
    {
        User::factory()->create([
            'email' => 'disabled.discord@example.com',
            'disabled_at' => now(),
        ]);

        Http::fake([
            'https://discord.com/api/oauth2.test/token' => Http::response([
                'access_token' => 'discord-access-token',
            ]),
            'https://discord.com/api/users/@me.test' => Http::response([
                'id' => 'discord-subject-disabled',
                'email' => 'disabled.discord@example.com',
                'verified' => true,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.discord_oauth_state' => 'known-state'])
            ->get(route('auth.discord.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('discord');
        $this->assertGuest();
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_DISCORD,
            'provider_subject' => 'discord-subject-disabled',
        ]);
    }
}
