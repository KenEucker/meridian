<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\SystemConfig\ApplySystemConfigOverrides;
use App\Services\SystemConfig\SystemConfigOverrideStore;
use App\Support\ApiTokenExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Node-configured bearer token expiry (M16.1).
 *
 * Source: AUTH-024; technical spec 11.4; data/API 5.4, 12.5. Token expiry is
 * independent of the 5-minute shared-workstation inactivity timeout in
 * technical spec 13.3.
 */
class ApiTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function issueTokenFor(User $user): string
    {
        return app(ApiTokenIssuer::class)->issue($user)->plainTextToken;
    }

    /**
     * Probe whether a token still authenticates.
     *
     * `DELETE /api/auth/session` is the only `auth:sanctum` route this
     * milestone adds, so it doubles as the probe. A 200 means the token was
     * accepted — and consumed, so each probe uses its own token.
     */
    private function probeWith(string $token): TestResponse
    {
        // Every HTTP call in one test shares a container, and a resolved guard
        // caches the user it found. A client makes each request against a fresh
        // process, so the guard is forgotten to match.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.auth.session.destroy'));
    }

    public function test_the_documented_default_lifetime_is_six_weeks(): void
    {
        $this->assertSame(60480, ApiTokenExpiry::DEFAULT_MINUTES);
        $this->assertSame(60480, ApiTokenExpiry::minutes());
        $this->assertSame(60480, config('meridian.api_tokens.expiration_minutes'));
        $this->assertSame(60480, config('sanctum.expiration'));
    }

    public function test_an_issued_token_carries_an_expiry_from_node_configuration(): void
    {
        config()->set('meridian.api_tokens.expiration_minutes', 120);

        $user = User::factory()->create();
        $this->issueTokenFor($user);

        $token = PersonalAccessToken::query()->sole();

        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(120)->timestamp,
            $token->expires_at->timestamp,
            5,
        );
    }

    public function test_a_token_stops_authenticating_once_its_lifetime_has_passed(): void
    {
        config()->set('meridian.api_tokens.expiration_minutes', 60);
        config()->set('sanctum.expiration', 60);

        $user = User::factory()->create();
        $beforeExpiry = $this->issueTokenFor($user);
        $afterExpiry = $this->issueTokenFor($user);

        $this->travel(59)->minutes();
        $this->probeWith($beforeExpiry)->assertOk();

        $this->travel(2)->minutes();
        $this->probeWith($afterExpiry)->assertUnauthorized();

        // Expiry is evaluated at request time rather than by deleting the row,
        // so an expired token is still on record for God Mode to account for.
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_lowering_the_node_setting_expires_tokens_already_issued(): void
    {
        config()->set('meridian.api_tokens.expiration_minutes', 10080);
        config()->set('sanctum.expiration', 10080);

        $user = User::factory()->create();
        $beforeChange = $this->issueTokenFor($user);
        $afterChange = $this->issueTokenFor($user);

        $this->travel(2)->days();
        $this->probeWith($beforeChange)->assertOk();

        // The guard requires both the configured lifetime and the token's own
        // `expires_at`, so a node that shortens the setting does not have to
        // wait out the lifetime its tokens were stamped with.
        config()->set('sanctum.expiration', 60);

        $this->probeWith($afterChange)->assertUnauthorized();
    }

    public function test_token_expiry_is_independent_of_the_shared_workstation_timeout(): void
    {
        // Technical spec 13.3 times a shared workstation out after 5 minutes of
        // inactivity. A personal device token is unaffected by that number.
        $user = User::factory()->create();
        $token = $this->issueTokenFor($user);

        $this->travel(30)->minutes();

        $this->probeWith($token)->assertOk();
    }

    public function test_the_lifetime_is_configurable_per_node_from_god_mode(): void
    {
        // AUTH-024 requires that expiry be configurable per node. The variable
        // is catalogued in .env.example, so a God Mode database override is the
        // path an operator actually uses, and it has to reach both config keys
        // — the one issuance stamps from and the one the guard enforces.
        $node = Node::factory()->create();

        app(SystemConfigOverrideStore::class)
            ->put($node, 'MERIDIAN_API_TOKEN_EXPIRATION_MINUTES', '180', null, 'test');

        $applier = app(ApplySystemConfigOverrides::class);
        $applier->fresh();
        $applier->apply();

        $this->assertContains('MERIDIAN_API_TOKEN_EXPIRATION_MINUTES', $applier->appliedNames());
        $this->assertSame(180, config('meridian.api_tokens.expiration_minutes'));
        $this->assertSame(180, config('sanctum.expiration'));

        $user = User::factory()->create();
        $this->issueTokenFor($user);

        $this->assertEqualsWithDelta(
            now()->addMinutes(180)->timestamp,
            PersonalAccessToken::query()->sole()->expires_at->timestamp,
            5,
        );
    }

    public function test_an_unusable_configured_lifetime_falls_back_to_the_documented_default(): void
    {
        // AUTH-024 requires that tokens expire, so a zero, negative, or
        // non-numeric setting must not be read as "never".
        config()->set('meridian.api_tokens.expiration_minutes', 0);

        $user = User::factory()->create();
        $this->issueTokenFor($user);

        $token = PersonalAccessToken::query()->sole();

        $this->assertEqualsWithDelta(
            now()->addMinutes(ApiTokenExpiry::DEFAULT_MINUTES)->timestamp,
            $token->expires_at->timestamp,
            5,
        );
    }
}
