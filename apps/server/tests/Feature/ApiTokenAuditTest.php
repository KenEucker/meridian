<?php

namespace Tests\Feature;

use App\Mail\ApiLoginCodeMail;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\ApiTokenAuthentication;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\ApiTokenRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Token issuance, expiry, and revocation audit, and raw-token redaction (M16.2).
 *
 * Source: AUTH-025; technical spec 11.4; data/API 12.5. Audit entries reference
 * a token by identifier and by bound device. Raw token values are never written
 * to logs, audit entries, or exports.
 */
class ApiTokenAuditTest extends TestCase
{
    use RefreshDatabase;

    private function probeWith(string $token): TestResponse
    {
        // Every HTTP call in one test shares a container, and a resolved guard
        // caches the user it found. A client makes each request against a fresh
        // process, so the guard is forgotten to match.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.auth.session.destroy'));
    }

    /**
     * Sign in over the API exactly as a client does, and return the plaintext
     * token that came back.
     */
    private function signIn(string $email, Device $device): string
    {
        Mail::fake();

        $this->postJson(route('api.auth.magic-link.store'), ['email' => $email])
            ->assertStatus(202);

        $code = null;

        Mail::assertSent(ApiLoginCodeMail::class, function (ApiLoginCodeMail $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return $this->postJson(route('api.auth.magic-link.verify'), [
            'email' => $email,
            'code' => $code,
            'client_name' => 'Meridian Field',
            'device' => ['id' => (string) $device->getKey()],
        ])->assertStatus(201)->json('token');
    }

    /**
     * Every stored audit row, as text, for asserting what is not in any of them.
     */
    private function auditText(): string
    {
        return AuditEvent::query()
            ->get()
            ->map(fn (AuditEvent $event): string => (string) json_encode($event->getAttributes()))
            ->implode("\n");
    }

    public function test_issuing_a_token_is_audited_by_identifier_and_bound_device(): void
    {
        $device = Device::factory()->create();

        $this->signIn('issued@example.com', $device);

        $token = ApiToken::query()->sole();
        $user = User::query()->where('email', 'issued@example.com')->sole();

        $audit = AuditEvent::query()->where('action', ApiTokenIssuer::AUDIT_ISSUED)->sole();

        $this->assertSame((string) $token->getKey(), $audit->entity_id);

        /*
         * And that identifier is a UUID (M16.11).
         *
         * `audit_events.entity_id` is a `uuid` column, because every entity
         * Meridian audits is identified by one. Sanctum's table arrived with an
         * auto-incrementing key, and PostgreSQL refuses an integer written into
         * that column outright — so on the database Meridian deploys on, this
         * required audit entry took token issuance down with it. The suite runs
         * on SQLite, which accepts the integer, so the shape is asserted here
         * rather than left to the driver to notice.
         */
        $this->assertTrue(Str::isUuid($audit->entity_id), 'A token is audited by a UUID identifier.');
        $this->assertTrue(Str::isUuid((string) $token->getKey()), 'A token is keyed by UUID.');

        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame((string) $device->getKey(), $audit->actor_device_id);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
        $this->assertSame((string) $device->getKey(), $audit->after_json['device_id']);
        $this->assertSame('Meridian Field', $audit->after_json['client_name']);
    }

    public function test_revoking_a_token_is_audited_with_the_reason_it_was_revoked(): void
    {
        $device = Device::factory()->create();
        $holder = User::factory()->create();

        app(ApiTokenIssuer::class)->issue($holder, $device);

        $token = ApiToken::query()->sole();
        $operator = User::factory()->create();

        app(ApiTokenRevoker::class)->revoke($token, $operator, ApiTokenRevoker::REASON_GOD_MODE_TOKEN);

        $audit = AuditEvent::query()->where('action', ApiTokenRevoker::AUDIT_REVOKED)->sole();

        $this->assertSame((string) $token->getKey(), $audit->entity_id);
        $this->assertSame($operator->id, $audit->actor_user_id);
        $this->assertSame((string) $device->getKey(), $audit->actor_device_id);
        $this->assertSame(ApiTokenRevoker::REASON_GOD_MODE_TOKEN, $audit->reason);
    }

    public function test_revoking_a_devices_tokens_audits_each_one(): void
    {
        $device = Device::factory()->create();

        app(ApiTokenIssuer::class)->issue(User::factory()->create(), $device);
        app(ApiTokenIssuer::class)->issue(User::factory()->create(), $device);

        app(ApiTokenRevoker::class)->revokeForDevice($device, User::factory()->create());

        // Two credentials ended, so two entries. A single entry naming a count
        // would leave a later reader unable to say which tokens it covered.
        $this->assertSame(2, AuditEvent::query()
            ->where('action', ApiTokenRevoker::AUDIT_REVOKED)
            ->where('reason', ApiTokenRevoker::REASON_GOD_MODE_DEVICE)
            ->count());
    }

    public function test_a_client_signing_itself_out_is_audited_as_the_client(): void
    {
        $plaintext = $this->signIn('selfout@example.com', Device::factory()->create());

        $this->probeWith($plaintext)->assertOk();

        $audit = AuditEvent::query()->where('action', ApiTokenRevoker::AUDIT_REVOKED)->sole();

        $this->assertSame(ApiTokenRevoker::REASON_CLIENT, $audit->reason);
        $this->assertSame(AuditEvent::SOURCE_API, $audit->source_context);
    }

    /**
     * Expiry is the one audited moment nobody performs, so it is recorded when
     * the node first observes it: the next request the expired token makes.
     */
    public function test_expiry_is_audited_when_the_node_first_sees_an_expired_token(): void
    {
        config()->set('meridian.api_tokens.expiration_minutes', 60);
        config()->set('sanctum.expiration', 60);

        $device = Device::factory()->create();
        $plaintext = app(ApiTokenIssuer::class)->issue(User::factory()->create(), $device)->plainTextToken;

        $this->travel(61)->minutes();

        $this->assertSame(0, AuditEvent::query()->where('action', ApiTokenAuthentication::AUDIT_EXPIRED)->count());

        $this->probeWith($plaintext)->assertUnauthorized();

        $audit = AuditEvent::query()->where('action', ApiTokenAuthentication::AUDIT_EXPIRED)->sole();

        $this->assertSame((string) ApiToken::query()->sole()->getKey(), $audit->entity_id);
        $this->assertSame((string) $device->getKey(), $audit->actor_device_id);
    }

    public function test_a_client_retrying_an_expired_token_is_audited_once(): void
    {
        config()->set('meridian.api_tokens.expiration_minutes', 60);
        config()->set('sanctum.expiration', 60);

        $plaintext = app(ApiTokenIssuer::class)
            ->issue(User::factory()->create(), Device::factory()->create())
            ->plainTextToken;

        $this->travel(61)->minutes();

        $this->probeWith($plaintext)->assertUnauthorized();
        $this->probeWith($plaintext)->assertUnauthorized();
        $this->probeWith($plaintext)->assertUnauthorized();

        $this->assertSame(1, AuditEvent::query()->where('action', ApiTokenAuthentication::AUDIT_EXPIRED)->count());
    }

    /**
     * A revoked token is refused for being revoked, not for expiring, so its
     * later requests do not accumulate expiry entries about it.
     */
    public function test_a_revoked_token_is_not_also_audited_as_expired(): void
    {
        $plaintext = $this->signIn('revoked@example.com', Device::factory()->create());

        $this->probeWith($plaintext)->assertOk();
        $this->probeWith($plaintext)->assertUnauthorized();

        $this->assertSame(0, AuditEvent::query()->where('action', ApiTokenAuthentication::AUDIT_EXPIRED)->count());
    }

    /**
     * AUTH-025: raw token values are never written to logs or audit entries.
     * The plaintext exists in the HTTP response and nowhere else.
     */
    public function test_no_raw_token_value_reaches_an_audit_entry_or_the_log(): void
    {
        Log::spy();

        $device = Device::factory()->create();
        $plaintext = $this->signIn('redacted@example.com', $device);
        $secret = explode('|', $plaintext, 2)[1];

        $token = ApiToken::query()->sole();

        // Sign out and expire, so every audited moment in a token's life has
        // been reached before the assertions run.
        $this->probeWith($plaintext)->assertOk();

        app(ApiTokenIssuer::class)->issue(User::factory()->create(), $device);
        config()->set('sanctum.expiration', 1);
        $this->travel(2)->minutes();

        $audited = $this->auditText();

        $this->assertNotSame('', $audited);
        $this->assertStringNotContainsString($plaintext, $audited);
        $this->assertStringNotContainsString($secret, $audited);

        // Not even the stored hash: an audit entry names the token by
        // identifier and by bound device.
        $this->assertStringNotContainsString($token->token, $audited);
        $this->assertStringContainsString((string) $token->getKey(), $audited);
        $this->assertStringContainsString((string) $device->getKey(), $audited);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }
}
