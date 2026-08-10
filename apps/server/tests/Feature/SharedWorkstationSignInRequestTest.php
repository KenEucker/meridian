<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\Node;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\SharedWorkstationSignInRequest;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\SharedWorkstationSignInPickupSecret;
use App\Services\Auth\SharedWorkstationSignInRequestException;
use App\Services\Auth\SharedWorkstationSignInRequestService;
use App\Services\Auth\SharedWorkstationSignInRequestThrottle;
use App\Services\Node\NodeSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Workstation sign-in requests: open, grant, collect (M18.59).
 *
 * Source: AUTH-032 through AUTH-035, AUTH-037; technical spec 13.4; data/API
 * 12.4A. A locked trusted workstation opens a request and receives the request
 * id — public, travels in the QR — and a pickup secret only it holds. A device
 * holding a session grants the request for its own user only, against the node
 * that issued it. The workstation collects with its pickup secret; a grant is
 * collectable once and only by the opener, and collection establishes a
 * shared-workstation session and nothing more.
 *
 * The re-authentication purpose (AUTH-036) is M18.62 and is asserted there;
 * what is asserted here is that a sign-in collection refuses any other purpose.
 */
class SharedWorkstationSignInRequestTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SharedWorkstationSignInRequestService
    {
        return app(SharedWorkstationSignInRequestService::class);
    }

    /**
     * A trusted shared workstation pinned to a real event, on a node with an
     * identity of its own to put into the QR.
     */
    private function workstation(array $attributes = []): SharedWorkstation
    {
        $event = Event::factory()->create();

        return SharedWorkstation::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
            ...$attributes,
        ]);
    }

    private function localNode(): Node
    {
        $existing = app(NodeSetupService::class)->activeNode();

        if ($existing instanceof Node) {
            return $existing;
        }

        return Node::query()->create([
            'node_name' => 'onsite-test-1',
            'node_role' => 'standalone',
            'is_local' => true,
            'public_key' => base64_encode(random_bytes(32)),
        ]);
    }

    private function signedIn(User $user, ?Device $device = null): string
    {
        return app(ApiTokenIssuer::class)
            ->issue($user, $device ?? Device::factory()->create(), 'Meridian Field')
            ->plainTextToken;
    }

    private function openRequest(SharedWorkstation $workstation): TestResponse
    {
        return $this->postJson(route('api.kiosk.workstations.sign-in-requests.store', $workstation));
    }

    private function grantRequest(string $token, string $requestId, array $payload = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.auth.workstation-sign-in-requests.grant', $requestId), $payload);
    }

    private function collectRequest(SharedWorkstation $workstation, string $requestId, string $secret): TestResponse
    {
        return $this->postJson(
            route('api.kiosk.workstations.sign-in-requests.collect', [$workstation, $requestId]),
            ['pickup_secret' => $secret],
        );
    }

    /**
     * Every stored audit row as text, for asserting what is in none of them.
     */
    private function auditText(): string
    {
        return AuditEvent::query()
            ->get()
            ->map(fn (AuditEvent $event): string => (string) json_encode($event->getAttributes()))
            ->implode("\n");
    }

    /*
    |--------------------------------------------------------------------------
    | Opening (AUTH-032, AUTH-037)
    |--------------------------------------------------------------------------
    */

    public function test_a_trusted_pinned_workstation_opens_a_request_and_receives_the_secret_once(): void
    {
        $this->localNode();
        $workstation = $this->workstation();

        $response = $this->openRequest($workstation);

        $response->assertStatus(201)
            ->assertJsonPath('shared_workstation.id', $workstation->getKey())
            ->assertJsonPath('shared_workstation.short_code', $workstation->short_code)
            ->assertJsonPath('node.name', 'onsite-test-1');

        $secret = (string) $response->json('pickup_secret');
        $this->assertNotSame('', $secret);

        // Only the keyed hash survives the response (AUTH-037).
        $record = SharedWorkstationSignInRequest::query()->sole();
        $this->assertSame(SharedWorkstationSignInPickupSecret::hash($secret), $record->pickup_secret_hash);
        $this->assertNotSame($secret, $record->pickup_secret_hash);
        $this->assertSame(SharedWorkstationSignInRequest::PURPOSE_SIGN_IN, $record->purpose);
        $this->assertSame($workstation->event_id, $record->event_id);
        $this->assertNotNull($record->expires_at);
    }

    public function test_an_untrusted_revoked_or_unpinned_workstation_is_refused_a_request(): void
    {
        $untrusted = $this->workstation(['trusted' => false]);
        $revoked = $this->workstation(['revoked_at' => now()]);
        $unpinned = SharedWorkstation::factory()->create([
            'organization_id' => null,
            'event_id' => null,
        ]);

        // Untrusted and revoked machines are a 404, exactly as the pinned
        // context read answers them: not a machine this node answers for.
        $this->openRequest($untrusted)->assertStatus(404);
        $this->openRequest($revoked)->assertStatus(404);

        $this->openRequest($unpinned)
            ->assertStatus(409)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_WORKSTATION_CONTEXT_UNPINNED);

        $this->assertSame(0, SharedWorkstationSignInRequest::query()->count());
    }

    public function test_opening_is_rate_limited_per_workstation(): void
    {
        $workstation = $this->workstation();
        $limit = app(SharedWorkstationSignInRequestThrottle::class)->openPerWorkstation();

        for ($i = 0; $i < $limit; $i++) {
            $this->openRequest($workstation)->assertStatus(201);
        }

        $this->openRequest($workstation)
            ->assertStatus(429)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_RATE_LIMITED);

        // The limit is the workstation's, not the node's.
        $this->openRequest($this->workstation())->assertStatus(201);
    }

    /*
    |--------------------------------------------------------------------------
    | Granting (AUTH-033, AUTH-034, AUTH-037)
    |--------------------------------------------------------------------------
    */

    public function test_a_device_holding_a_session_grants_a_request_for_its_own_user(): void
    {
        $workstation = $this->workstation();
        $user = User::factory()->create();

        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');

        $this->grantRequest($this->signedIn($user), $requestId)
            ->assertStatus(200)
            ->assertJsonPath('granted', true)
            ->assertJsonPath('shared_workstation.name', $workstation->name);

        $record = SharedWorkstationSignInRequest::query()->sole();
        $this->assertSame($user->getKey(), $record->granted_by_user_id);
        $this->assertNotNull($record->granted_at);
    }

    public function test_a_grant_naming_another_user_is_refused(): void
    {
        $workstation = $this->workstation();
        $somebodyElse = User::factory()->create();

        $requestId = (string) $this->openRequest($workstation)->json('sign_in_request.id');

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId, [
            'user_id' => $somebodyElse->getKey(),
        ])
            ->assertStatus(403)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_GRANT_SCOPE);

        $this->assertNull(SharedWorkstationSignInRequest::query()->sole()->granted_at);
    }

    public function test_a_granted_request_cannot_be_granted_again(): void
    {
        $workstation = $this->workstation();
        $requestId = (string) $this->openRequest($workstation)->json('sign_in_request.id');

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId)->assertStatus(200);

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId)
            ->assertStatus(409)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_ALREADY_GRANTED);
    }

    public function test_a_grant_against_a_foreign_node_identity_is_refused(): void
    {
        $local = $this->localNode();
        $workstation = $this->workstation();
        $requestId = (string) $this->openRequest($workstation)->json('sign_in_request.id');

        // A peer node pairing taught this install about, whose name the
        // refusal can therefore include.
        $peer = Node::query()->create([
            'node_name' => 'central-test-1',
            'node_role' => 'central',
            'is_local' => false,
            'public_key' => base64_encode(random_bytes(32)),
        ]);

        $response = $this->grantRequest($this->signedIn(User::factory()->create()), $requestId, [
            'node_id' => (string) $peer->getKey(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_FOREIGN_NODE);

        // Both node identities are named (AUTH-034), so the person holding the
        // phone can tell which device is pointed at the wrong place.
        $message = (string) $response->json('message');
        $this->assertStringContainsString($local->node_name, $message);
        $this->assertStringContainsString('central-test-1', $message);

        $this->assertNull(SharedWorkstationSignInRequest::query()->sole()->granted_at);

        // The matching identity is accepted.
        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId, [
            'node_id' => (string) $local->getKey(),
        ])->assertStatus(200);
    }

    public function test_granting_is_rate_limited_per_user(): void
    {
        $workstation = $this->workstation();
        $user = User::factory()->create();
        $token = $this->signedIn($user);
        $limit = app(SharedWorkstationSignInRequestThrottle::class)->grantPerUser();

        // Spend the user's grant budget on requests that are already granted,
        // so every attempt counts and none succeeds twice.
        $requestIds = [];
        for ($i = 0; $i < $limit; $i++) {
            $requestIds[] = (string) $this->openRequest($this->workstation())->json('sign_in_request.id');
        }

        foreach ($requestIds as $requestId) {
            $this->grantRequest($token, $requestId)->assertStatus(200);
        }

        $extra = (string) $this->openRequest($this->workstation())->json('sign_in_request.id');

        $this->grantRequest($token, $extra)
            ->assertStatus(429)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_RATE_LIMITED);

        // The limit is the user's: somebody else still grants.
        $this->grantRequest($this->signedIn(User::factory()->create()), $extra)->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry (AUTH-032)
    |--------------------------------------------------------------------------
    */

    public function test_an_expired_request_can_be_neither_granted_nor_collected(): void
    {
        $workstation = $this->workstation();
        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->travel($this->service()->ttlSeconds() + 1)->seconds();

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId)
            ->assertStatus(410)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_REQUEST_EXPIRED);

        $this->collectRequest($workstation, $requestId, $secret)
            ->assertStatus(410)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_REQUEST_EXPIRED);

        $this->assertSame(0, SharedWorkstationSession::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Collection (AUTH-032, AUTH-035)
    |--------------------------------------------------------------------------
    */

    public function test_an_ungranted_request_collects_as_pending(): void
    {
        $workstation = $this->workstation();
        $opened = $this->openRequest($workstation);

        $this->collectRequest(
            $workstation,
            (string) $opened->json('sign_in_request.id'),
            (string) $opened->json('pickup_secret'),
        )
            ->assertStatus(200)
            ->assertJsonPath('status', 'pending');

        $this->assertSame(0, SharedWorkstationSession::query()->count());
    }

    public function test_only_the_opener_collects(): void
    {
        $workstation = $this->workstation();
        $event = $workstation->event()->firstOrFail();

        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId)->assertStatus(200);

        // A wrong secret is the same unknown as a request that does not exist.
        $this->collectRequest($workstation, $requestId, 'not-the-secret')
            ->assertStatus(404)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_REQUEST_UNKNOWN);

        // Another workstation presenting the *right* secret is refused too:
        // the grant belongs to the machine that opened the request.
        $other = SharedWorkstation::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);

        $this->collectRequest($other, $requestId, $secret)
            ->assertStatus(404)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_REQUEST_UNKNOWN);

        $this->assertSame(0, SharedWorkstationSession::query()->count());
    }

    public function test_collecting_a_granted_request_establishes_a_session_and_nothing_more(): void
    {
        $workstation = $this->workstation();
        $user = User::factory()->create();

        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->grantRequest($this->signedIn($user), $requestId)->assertStatus(200);

        $tokensBefore = ApiToken::query()->count();
        $trustsBefore = DeviceTrust::query()->count();

        $response = $this->collectRequest($workstation, $requestId, $secret);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'collected')
            ->assertJsonPath('user.id', $user->getKey())
            ->assertJsonPath('event_id', $workstation->event_id);

        $this->assertNotSame('', (string) $response->json('session_key'));

        // AUTH-035: a session and nothing more. No token, no device trust, and
        // no token-shaped key in the response.
        $this->assertSame($tokensBefore, ApiToken::query()->count());
        $this->assertSame($trustsBefore, DeviceTrust::query()->count());
        $this->assertNull($response->json('token'));
        $this->assertNull($response->json('access_token'));

        $session = SharedWorkstationSession::query()->sole();
        $this->assertSame($user->getKey(), $session->user_id);
        $this->assertSame($workstation->event_id, $session->event_id);
        $this->assertNull($session->login_code_id);
        $this->assertSame($requestId, (string) $session->sign_in_request_id);
    }

    public function test_a_granted_request_is_collectable_once(): void
    {
        $workstation = $this->workstation();

        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId)->assertStatus(200);

        $this->collectRequest($workstation, $requestId, $secret)->assertStatus(201);

        $this->collectRequest($workstation, $requestId, $secret)
            ->assertStatus(404)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_REQUEST_UNKNOWN);

        $this->assertSame(1, SharedWorkstationSession::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Audit and secrecy (AUTH-037)
    |--------------------------------------------------------------------------
    */

    public function test_opening_granting_and_collection_are_audited(): void
    {
        $workstation = $this->workstation();
        $user = User::factory()->create();

        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');

        $this->grantRequest($this->signedIn($user), $requestId)->assertStatus(200);
        $this->collectRequest($workstation, $requestId, (string) $opened->json('pickup_secret'))->assertStatus(201);

        foreach ([
            SharedWorkstationSignInRequestService::AUDIT_OPENED,
            SharedWorkstationSignInRequestService::AUDIT_GRANTED,
            SharedWorkstationSignInRequestService::AUDIT_COLLECTED,
        ] as $action) {
            $entry = AuditEvent::query()->where('action', $action)->sole();
            $this->assertSame($requestId, (string) $entry->entity_id, $action);
        }

        $granted = AuditEvent::query()->where('action', SharedWorkstationSignInRequestService::AUDIT_GRANTED)->sole();
        $this->assertSame($user->getKey(), $granted->actor_user_id);
    }

    public function test_no_request_secret_reaches_the_log_the_audit_trail_or_any_export(): void
    {
        Log::spy();

        $workstation = $this->workstation();

        $opened = $this->openRequest($workstation);
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->grantRequest($this->signedIn(User::factory()->create()), $requestId)->assertStatus(200);
        $collected = $this->collectRequest($workstation, $requestId, $secret);
        $sessionKey = (string) $collected->json('session_key');

        $audited = $this->auditText();

        // Neither secret, nor either one's stored hash, is in any audit entry;
        // entries name the request by identifier (AUTH-037). Nothing here is
        // exportable either — the model backs no Orchid source and no export
        // path serves it — so the audit trail is the only place a secret could
        // have leaked into at rest.
        $this->assertStringNotContainsString($secret, $audited);
        $this->assertStringNotContainsString($sessionKey, $audited);
        $this->assertStringNotContainsString(SharedWorkstationSignInPickupSecret::hash($secret), $audited);
        $this->assertStringContainsString($requestId, $audited);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }
}
