<?php

namespace Tests\Feature;

use App\Mail\MagicLinkLoginMail;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MagicLinkAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_is_publicly_reachable(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Sign in to Meridian');
    }

    public function test_requesting_magic_link_sends_signed_verification_email(): void
    {
        Mail::fake();

        $response = $this->post(route('auth.magic-link.store'), [
            'email' => 'Volunteer@Example.com',
        ]);

        $response->assertRedirect(route('auth.magic-link.sent'));

        Mail::assertSent(MagicLinkLoginMail::class, function (MagicLinkLoginMail $mail): bool {
            return str_contains($mail->verificationUrl, 'login/magic-link/verify')
                && str_contains($mail->verificationUrl, 'signature=');
        });

        Mail::assertSent(MagicLinkLoginMail::class, function (MagicLinkLoginMail $mail): bool {
            return $mail->hasTo('volunteer@example.com');
        });
    }

    public function test_magic_link_email_body_preserves_query_string_separators(): void
    {
        URL::forceRootUrl('http://127.0.0.1:8000');

        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl('test@example.com');
        $rendered = (new MagicLinkLoginMail($verificationUrl))->render();

        $this->assertStringContainsString($verificationUrl, $rendered);
        $this->assertStringNotContainsString('&amp;', $rendered);
    }

    public function test_magic_link_accepts_same_path_on_different_host(): void
    {
        URL::forceRootUrl('http://localhost:8000');

        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl('host@example.com');
        $path = parse_url($verificationUrl, PHP_URL_PATH);
        $query = parse_url($verificationUrl, PHP_URL_QUERY);

        $response = $this->get('http://127.0.0.1:8000'.$path.'?'.$query);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    public function test_magic_link_sent_screen_shows_normalized_email(): void
    {
        $response = $this->withSession([
            'magic_link_email' => 'volunteer@example.com',
        ])->get(route('auth.magic-link.sent'));

        $response->assertOk();
        $response->assertSee('volunteer@example.com');
    }

    public function test_valid_magic_link_creates_user_auth_identity_and_session(): void
    {
        $email = 'new.user@example.com';
        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl($email);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $user = User::query()->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => AuthIdentity::PROVIDER_EMAIL,
            'provider_subject' => $email,
            'provider_email' => $email,
            'provider_email_verified' => true,
        ]);

        $this->assertTrue(Auth::user()->is($user));
    }

    public function test_magic_link_user_creation_can_be_disabled(): void
    {
        config()->set('meridian.magic_link.allow_account_creation', false);

        $email = 'new.disabled-creation@example.com';
        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl($email);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertDatabaseMissing('auth_identities', [
            'provider' => AuthIdentity::PROVIDER_EMAIL,
            'provider_subject' => $email,
        ]);
    }

    public function test_valid_magic_link_logs_in_existing_user_and_syncs_email_identity(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'email_verified_at' => null,
        ]);

        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl($user->email);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => AuthIdentity::PROVIDER_EMAIL,
            'provider_subject' => 'existing@example.com',
            'provider_email' => 'existing@example.com',
            'provider_email_verified' => true,
        ]);
    }

    public function test_disabled_user_cannot_complete_magic_link_login(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'disabled_at' => now(),
        ]);

        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl($user->email);

        $response = $this->get($verificationUrl);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_expired_magic_link_is_rejected(): void
    {
        $email = 'expired@example.com';

        $verificationUrl = URL::temporarySignedRoute(
            'auth.magic-link.verify',
            now()->subMinute(),
            ['email' => $email],
        );

        $response = $this->get($verificationUrl);

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_tampered_magic_link_is_rejected(): void
    {
        $verificationUrl = app(MagicLinkService::class)->createVerificationUrl('secure@example.com');
        $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=invalid', $verificationUrl);

        $response = $this->get($tamperedUrl);

        $response->assertForbidden();
        $this->assertGuest();
    }

    public function test_invalid_magic_link_request_email_is_rejected(): void
    {
        Mail::fake();

        $response = $this->from(route('login'))->post(route('auth.magic-link.store'), [
            'email' => 'not-an-email',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }

    public function test_authenticated_home_route_requires_session(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('home'))->assertOk();
    }

    public function test_logout_page_requires_authentication(): void
    {
        $this->get(route('logout'))->assertRedirect(route('login'));
    }

    public function test_logout_page_is_reachable_when_authenticated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('logout'))
            ->assertOk()
            ->assertSee('Sign out');
    }

    public function test_logout_clears_session_and_redirects_to_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout.destroy'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();
    }
}
