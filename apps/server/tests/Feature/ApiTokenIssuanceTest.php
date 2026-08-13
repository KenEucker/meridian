<?php

namespace Tests\Feature;

use App\Mail\ApiLoginCodeMail;
use App\Models\ApiLoginCode;
use App\Models\ApiToken;
use App\Models\AuthIdentity;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\ApiLoginCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * API login endpoints and bearer token issuance (M16.1).
 *
 * Source: AUTH-018, AUTH-019, AUTH-024; technical spec 11.4; data/API 5.4.
 * Device binding and revocation (M16.2) have their own scripts; what is
 * asserted here is the login exchange, so these cases sign in from an
 * already-registered device.
 */
class ApiTokenIssuanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The device a client signs in from. Every issued token is bound to one
     * (AUTH-021), so the verify request carries it.
     *
     * @return array<string, string>
     */
    private function devicePayload(): array
    {
        return ['id' => (string) Device::factory()->create()->getKey()];
    }

    /**
     * Capture the plaintext code out of the mail Meridian sends, which is the
     * only place it exists — exactly as a real client obtains it.
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

    public function test_requesting_a_login_code_mails_it_and_stores_only_a_hash(): void
    {
        Mail::fake();

        $response = $this->postJson(route('api.auth.magic-link.store'), [
            'email' => 'Volunteer@Example.com',
        ]);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'sent']);
        $response->assertJsonPath('expires_in_minutes', 15);

        Mail::assertSent(ApiLoginCodeMail::class, fn (ApiLoginCodeMail $mail): bool => $mail->hasTo('volunteer@example.com'));

        $record = ApiLoginCode::query()->where('email', 'volunteer@example.com')->sole();

        $this->assertNull($record->used_at);
        $this->assertSame(0, $record->attempts);
        $this->assertTrue($record->expires_at->isFuture());

        // The mailed code appears nowhere in the row that records it.
        Mail::assertSent(ApiLoginCodeMail::class, function (ApiLoginCodeMail $mail) use ($record): bool {
            $normalized = ApiLoginCodeGenerator::normalize($mail->code);

            $this->assertStringNotContainsString($normalized, json_encode($record->getAttributes()));
            $this->assertSame(ApiLoginCodeGenerator::hash($normalized), $record->code_hash);

            return true;
        });
    }

    public function test_requesting_a_login_code_never_writes_the_code_to_the_log(): void
    {
        Mail::fake();
        Log::spy();

        $this->postJson(route('api.auth.magic-link.store'), ['email' => 'quiet@example.com'])
            ->assertStatus(202);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_requesting_a_login_code_rejects_an_invalid_email(): void
    {
        Mail::fake();

        $this->postJson(route('api.auth.magic-link.store'), ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('api_login_codes', 0);
    }

    public function test_verifying_a_code_issues_a_bearer_token_for_a_new_user(): void
    {
        $code = $this->requestCodeFor('new.client@example.com');

        $response = $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'new.client@example.com',
            'code' => $code,
            'client_name' => 'Meridian Field (Pixel 9)',
            'device' => $this->devicePayload(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('token_type', 'Bearer');
        $response->assertJsonPath('user.email', 'new.client@example.com');
        $response->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email']]);

        $user = User::query()->where('email', 'new.client@example.com')->sole();
        $this->assertNotNull($user->email_verified_at);

        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => AuthIdentity::PROVIDER_EMAIL,
            'provider_subject' => 'new.client@example.com',
            'provider_email_verified' => true,
        ]);

        $token = PersonalAccessToken::query()->sole();
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame('Meridian Field (Pixel 9)', $token->name);
    }

    public function test_an_issued_token_authenticates_an_api_request(): void
    {
        $code = $this->requestCodeFor('client@example.com');

        $token = $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'client@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.auth.session.destroy'))
            ->assertOk();
    }

    public function test_the_api_refuses_a_request_with_no_token(): void
    {
        $this->deleteJson(route('api.auth.session.destroy'))->assertUnauthorized();
    }

    public function test_a_browser_session_does_not_authenticate_a_bearer_token_route(): void
    {
        // AUTH-018: client applications authenticate with a bearer token rather
        // than a browser session cookie. Sanctum's default guard fallback would
        // have let the console's session through; config/sanctum.php removes it.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson(route('api.auth.session.destroy'))
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_authenticating_on_its_next_request(): void
    {
        $code = $this->requestCodeFor('revoker@example.com');

        $token = $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'revoker@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.auth.session.destroy'))
            ->assertOk();

        // The row stays on record with a revocation stamp rather than being
        // deleted, so God Mode can still account for the credential that
        // existed and the audit entries naming it keep resolving (AUTH-025).
        $this->assertNotNull(ApiToken::query()->sole()->revoked_at);

        // Every HTTP call in one test shares a container, and a resolved guard
        // caches the user it found. A client makes each request against a fresh
        // process, so the guard is forgotten to match.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.auth.session.destroy'))
            ->assertUnauthorized();
    }

    public function test_a_login_code_is_single_use(): void
    {
        $code = $this->requestCodeFor('once@example.com');

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'once@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])->assertStatus(201);

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'once@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'invalid_login_code');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_code_is_accepted_with_the_formatting_a_person_types(): void
    {
        $code = $this->requestCodeFor('typed@example.com');

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'typed@example.com',
            'code' => strtolower(ApiLoginCodeGenerator::format($code)),
            'device' => $this->devicePayload(),
        ])->assertStatus(201);
    }

    public function test_a_code_belonging_to_another_address_does_not_authenticate(): void
    {
        $code = $this->requestCodeFor('owner@example.com');
        $this->requestCodeFor('bystander@example.com');

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'bystander@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'invalid_login_code');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_wrong_code_counts_against_the_attempt_limit(): void
    {
        $code = $this->requestCodeFor('guessed@example.com');

        for ($attempt = 0; $attempt < ApiLoginCode::attemptLimit(); $attempt++) {
            $this->postJson(route('api.auth.magic-link.verify'), [
                'email' => 'guessed@example.com',
                'code' => 'ZZZZZZZZ',
                'device' => $this->devicePayload(),
            ])->assertStatus(401);
        }

        $this->assertSame(
            ApiLoginCode::attemptLimit(),
            ApiLoginCode::query()->where('email', 'guessed@example.com')->sole()->attempts,
        );

        // The real code is retired along with the exhausted attempts.
        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'guessed@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])->assertStatus(401);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_expired_code_does_not_issue_a_token(): void
    {
        $code = $this->requestCodeFor('stale@example.com');

        $this->travel(config('meridian.api_tokens.login_code.expires_minutes') + 1)->minutes();

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'stale@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])
            ->assertStatus(401)
            ->assertJsonPath('reason', 'invalid_login_code');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_disabled_user_cannot_exchange_a_code_for_a_token(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.com',
            'disabled_at' => now(),
        ]);

        $code = $this->requestCodeFor('disabled@example.com');

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'disabled@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'account_disabled');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_address_cannot_sign_in_when_account_creation_is_disabled(): void
    {
        config()->set('meridian.magic_link.allow_account_creation', false);

        $code = $this->requestCodeFor('stranger@example.com');

        $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'stranger@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'account_creation_disabled');

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Neither login route can carry a session, so the rate limit is what
     * stands between the endpoints and mail flooding or code guessing. The
     * budget is per client-and-address pair with a per-client ceiling above
     * it — a plain per-client limit refused one person's first request
     * because of somebody else's retries from the same machine.
     */
    public function test_requesting_login_codes_is_rate_limited_per_address(): void
    {
        Mail::fake();

        for ($request = 0; $request < 3; $request++) {
            $this->postJson(route('api.auth.magic-link.store'), ['email' => 'flood@example.com'])
                ->assertStatus(202);
        }

        $this->postJson(route('api.auth.magic-link.store'), ['email' => 'flood@example.com'])
            ->assertStatus(429);

        // A different address from the same client has a budget of its own:
        // one inbox's retries do not refuse another person's first request.
        $this->postJson(route('api.auth.magic-link.store'), ['email' => 'fresh@example.com'])
            ->assertStatus(202);
    }

    public function test_requesting_login_codes_is_capped_per_client_across_addresses(): void
    {
        Mail::fake();

        // Five addresses at their full three-request budgets is the documented
        // fifteen-per-minute client ceiling.
        for ($address = 0; $address < 5; $address++) {
            for ($request = 0; $request < 3; $request++) {
                $this->postJson(route('api.auth.magic-link.store'), [
                    'email' => "walk{$address}@example.com",
                ])->assertStatus(202);
            }
        }

        // The sixth address is within its own budget, and is refused by the
        // client ceiling — the bound on what one machine can make the mailer
        // do, however many addresses it walks.
        $this->postJson(route('api.auth.magic-link.store'), ['email' => 'walk5@example.com'])
            ->assertStatus(429);
    }

    public function test_the_stored_token_is_a_hash_and_never_the_plaintext(): void
    {
        $code = $this->requestCodeFor('hashed@example.com');

        $plaintext = $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => 'hashed@example.com',
            'code' => $code,
            'device' => $this->devicePayload(),
        ])->json('token');

        $stored = PersonalAccessToken::query()->sole();

        $this->assertStringNotContainsString($stored->token, $plaintext);
        $this->assertNotSame($plaintext, $stored->token);
        $this->assertSame(hash('sha256', explode('|', $plaintext, 2)[1]), $stored->token);
    }
}
