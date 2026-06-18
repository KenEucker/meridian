<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meridian.oauth.google.client_id', 'google-client-id');
        config()->set('meridian.oauth.google.client_secret', 'google-client-secret');
        config()->set('meridian.oauth.google.redirect_uri', 'http://127.0.0.1:8000/login/google/callback');
        config()->set('meridian.oauth.google.token_url', 'https://oauth2.googleapis.test/token');
        config()->set('meridian.oauth.google.userinfo_url', 'https://openidconnect.googleapis.test/v1/userinfo');
        config()->set('meridian.oauth.post_login_redirect', '/home');
    }

    public function test_login_screen_links_to_google_oauth(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee(route('auth.google.redirect'));
        $response->assertSee('Continue with Google');
    }

    public function test_google_redirect_generates_state_and_authorization_url(): void
    {
        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $response->assertSessionHas('auth.google_oauth_state');

        $location = $response->headers->get('Location');

        $this->assertIsString($location);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('client_id=google-client-id', $location);
        $this->assertStringContainsString('redirect_uri=http%3A%2F%2F127.0.0.1%3A8000%2Flogin%2Fgoogle%2Fcallback', $location);
        $this->assertStringContainsString('response_type=code', $location);
        $this->assertStringContainsString('scope=openid%20email%20profile', $location);
        $this->assertStringContainsString('state=', $location);
    }

    public function test_google_redirect_fails_safely_when_config_is_missing(): void
    {
        config()->set('meridian.oauth.google.client_id', null);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $response->assertSessionMissing('auth.google_oauth_state');
    }

    public function test_google_callback_creates_user_auth_identity_and_session_for_verified_email(): void
    {
        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response([
                'access_token' => 'google-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://openidconnect.googleapis.test/v1/userinfo' => Http::response([
                'sub' => 'google-subject-123',
                'email' => 'Volunteer@Example.com',
                'email_verified' => true,
                'name' => 'Volunteer Person',
            ]),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
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
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-123',
            'provider_email' => 'volunteer@example.com',
            'provider_email_verified' => true,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://oauth2.googleapis.test/token'
                && $request['code'] === 'valid-code'
                && $request['grant_type'] === 'authorization_code';
        });

        Http::assertSent(fn ($request): bool => $request->url() === 'https://openidconnect.googleapis.test/v1/userinfo');
    }

    public function test_google_callback_links_same_verified_email_to_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'same.email@example.com',
        ]);

        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response([
                'access_token' => 'google-access-token',
            ]),
            'https://openidconnect.googleapis.test/v1/userinfo' => Http::response([
                'sub' => 'google-subject-existing',
                'email' => 'Same.Email@Example.com',
                'email_verified' => true,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
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
    }

    public function test_google_callback_rejects_unverified_email_without_creating_identity(): void
    {
        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response([
                'access_token' => 'google-access-token',
            ]),
            'https://openidconnect.googleapis.test/v1/userinfo' => Http::response([
                'sub' => 'google-subject-unverified',
                'email' => 'unverified@example.com',
                'email_verified' => false,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-unverified',
        ]);
    }

    public function test_google_callback_rejects_missing_email_without_creating_identity(): void
    {
        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response([
                'access_token' => 'google-access-token',
            ]),
            'https://openidconnect.googleapis.test/v1/userinfo' => Http::response([
                'sub' => 'google-subject-missing-email',
                'email_verified' => true,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-missing-email',
        ]);
    }

    public function test_google_callback_rejects_state_mismatch_without_provider_requests(): void
    {
        Http::fake();

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
                'state' => 'different-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_google_callback_handles_token_connection_errors_without_exception_page(): void
    {
        Http::fake([
            'https://oauth2.googleapis.test/token' => fn () => throw new ConnectionException('cURL error 60'),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_google_callback_handles_userinfo_connection_errors_without_exception_page(): void
    {
        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response([
                'access_token' => 'google-access-token',
            ]),
            'https://openidconnect.googleapis.test/v1/userinfo' => fn () => throw new ConnectionException('cURL error 60'),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_complete_google_login(): void
    {
        User::factory()->create([
            'email' => 'disabled.google@example.com',
            'disabled_at' => now(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.test/token' => Http::response([
                'access_token' => 'google-access-token',
            ]),
            'https://openidconnect.googleapis.test/v1/userinfo' => Http::response([
                'sub' => 'google-subject-disabled',
                'email' => 'disabled.google@example.com',
                'email_verified' => true,
            ]),
        ]);

        $response = $this
            ->withSession(['auth.google_oauth_state' => 'known-state'])
            ->get(route('auth.google.callback', [
                'state' => 'known-state',
                'code' => 'valid-code',
            ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_GOOGLE,
            'provider_subject' => 'google-subject-disabled',
        ]);
    }
}
