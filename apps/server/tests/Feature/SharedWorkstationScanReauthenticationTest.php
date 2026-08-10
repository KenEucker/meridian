<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\SharedWorkstationSignInRequest;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use App\Services\Auth\SharedWorkstationSessionKey;
use App\Services\Auth\SharedWorkstationSessionService;
use App\Services\Auth\SharedWorkstationSignInRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Re-authentication by scan (M18.62).
 *
 * Source: AUTH-036; UI contract 18.2; technical spec 13.2, 13.4. A live
 * session's re-authentication runs over the same request mechanism as
 * sign-in, with its purpose recorded and the request bound to the session.
 * Only a grant from that session's own user confirms it; a grant from anybody
 * else is refused and hands nothing over. A re-authentication request cannot
 * be collected as a sign-in nor the reverse, and both the typed and the scan
 * path stamp `reauthenticated_at` the same way.
 */
class SharedWorkstationScanReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** The session's raw key, so requests can be made as the workstation. */
    private string $sessionKey;

    private SharedWorkstationSession $session;

    private SharedWorkstation $workstation;

    private User $signedInUser;

    protected function setUp(): void
    {
        parent::setUp();

        $event = Event::factory()->create();
        $this->workstation = SharedWorkstation::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);
        $this->signedInUser = User::factory()->create();

        $issued = app(SharedWorkstationLoginCodeService::class)
            ->generateForUser($this->signedInUser, User::factory()->create(), $this->workstation);

        $established = app(SharedWorkstationSessionService::class)
            ->start($this->workstation, $issued->plaintextCode);

        $this->session = $established->record;
        $this->sessionKey = $established->sessionKey;
    }

    private function asWorkstation(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader(SharedWorkstationSessionKey::HEADER, $this->sessionKey);
    }

    private function openReauth(): TestResponse
    {
        return $this->asWorkstation()->postJson(
            route('api.auth.shared-workstation-session.reauthentication-requests.store'),
        );
    }

    private function grantAs(User $user, string $requestId): TestResponse
    {
        $token = app(ApiTokenIssuer::class)
            ->issue($user, Device::factory()->create(), 'Meridian Field')
            ->plainTextToken;

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.auth.workstation-sign-in-requests.grant', $requestId));
    }

    private function collectReauth(string $requestId, string $secret): TestResponse
    {
        return $this->asWorkstation()->postJson(
            route('api.auth.shared-workstation-session.reauthentication-requests.collect', $requestId),
            ['pickup_secret' => $secret],
        );
    }

    public function test_a_granted_request_confirms_the_session_and_stamps_reauthenticated_at(): void
    {
        $opened = $this->openReauth();

        $opened->assertStatus(201)
            ->assertJsonPath('sign_in_request.purpose', SharedWorkstationSignInRequest::PURPOSE_REAUTHENTICATION);

        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        // The request is bound to the live session (AUTH-036).
        $record = SharedWorkstationSignInRequest::query()->sole();
        $this->assertSame((string) $this->session->getKey(), (string) $record->shared_workstation_session_id);

        // Pending until somebody grants.
        $this->collectReauth($requestId, $secret)
            ->assertStatus(200)
            ->assertJsonPath('status', 'pending');

        $this->grantAs($this->signedInUser, $requestId)->assertStatus(200);

        $collected = $this->collectReauth($requestId, $secret);

        $collected->assertStatus(200)
            ->assertJsonPath('status', 'collected')
            ->assertJsonPath('user.id', $this->signedInUser->getKey());

        $this->assertNotNull($collected->json('session.reauthenticated_at'));

        $session = $this->session->fresh();
        $this->assertNotNull($session->reauthenticated_at);

        // The same session, confirmed — not a new one (AUTH-036 hands nothing
        // over), and no session key in the response.
        $this->assertSame(1, SharedWorkstationSession::query()->count());
        $this->assertNull($collected->json('session_key'));
    }

    public function test_a_grant_from_another_user_is_refused_and_the_session_is_unchanged(): void
    {
        $opened = $this->openReauth();
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->grantAs(User::factory()->create(), $requestId)
            ->assertStatus(403)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_GRANT_SCOPE);

        // Nothing was granted, so the poll stays pending and the session is
        // untouched: no reauthentication stamp, same user, still live.
        $this->collectReauth($requestId, $secret)
            ->assertStatus(200)
            ->assertJsonPath('status', 'pending');

        $session = $this->session->fresh();
        $this->assertNull($session->reauthenticated_at);
        $this->assertSame($this->signedInUser->getKey(), $session->user_id);
        $this->assertNull($session->ended_at);
    }

    public function test_a_reauthentication_request_cannot_be_collected_as_a_sign_in_or_the_reverse(): void
    {
        // A re-authentication request presented to the sign-in collector.
        $reauth = $this->openReauth();
        $reauthId = (string) $reauth->json('sign_in_request.id');
        $reauthSecret = (string) $reauth->json('pickup_secret');

        $this->grantAs($this->signedInUser, $reauthId)->assertStatus(200);

        $this->postJson(
            route('api.kiosk.workstations.sign-in-requests.collect', [$this->workstation, $reauthId]),
            ['pickup_secret' => $reauthSecret],
        )
            ->assertStatus(409)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_PURPOSE_MISMATCH);

        // And a sign-in request presented to the re-authentication collector.
        $signIn = $this->postJson(
            route('api.kiosk.workstations.sign-in-requests.store', $this->workstation),
        );
        $signInId = (string) $signIn->json('sign_in_request.id');
        $signInSecret = (string) $signIn->json('pickup_secret');

        // Bound to no session, so the collector answers unknown before purpose
        // is even reached — a request that is not this session's to collect.
        $this->collectReauth($signInId, $signInSecret)
            ->assertStatus(404)
            ->assertJsonPath('reason', SharedWorkstationSignInRequestException::REASON_REQUEST_UNKNOWN);

        // Neither collection confirmed anything and no new session exists.
        $this->assertNull($this->session->fresh()->reauthenticated_at);
        $this->assertSame(1, SharedWorkstationSession::query()->count());
    }

    /**
     * M18.62: `reauthenticated_at` is recorded identically whichever path
     * confirmed it — the same audit action and the same stamp — so a
     * privileged action's audit trail does not depend on how the person proved
     * they were standing there.
     */
    public function test_both_paths_stamp_reauthenticated_at_the_same_way(): void
    {
        // The typed path.
        $typedCode = app(SharedWorkstationLoginCodeService::class)
            ->generateForUser($this->signedInUser, User::factory()->create(), $this->workstation);

        $this->asWorkstation()
            ->postJson(route('api.auth.shared-workstation-session.reauthenticate'), [
                'code' => $typedCode->plaintextCode,
            ])
            ->assertStatus(200);

        $typedStamp = $this->session->fresh()->reauthenticated_at;
        $this->assertNotNull($typedStamp);

        // The scan path, a moment later.
        $this->travel(1)->minutes();

        $opened = $this->openReauth();
        $requestId = (string) $opened->json('sign_in_request.id');
        $secret = (string) $opened->json('pickup_secret');

        $this->grantAs($this->signedInUser, $requestId)->assertStatus(200);
        $this->collectReauth($requestId, $secret)->assertStatus(200);

        $scanStamp = $this->session->fresh()->reauthenticated_at;
        $this->assertNotNull($scanStamp);
        $this->assertTrue($scanStamp->gt($typedStamp));

        // One audit action for both, distinguished only by provenance: the
        // typed entry names the login code, the scanned one the request.
        $entries = AuditEvent::query()
            ->where('action', SharedWorkstationSessionService::AUDIT_REAUTHENTICATED)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertArrayHasKey('login_code_id', $entries[0]->after_json);
        $this->assertArrayHasKey('sign_in_request_id', $entries[1]->after_json);

        foreach ($entries as $entry) {
            $this->assertSame((string) $this->session->getKey(), (string) $entry->entity_id);
            $this->assertSame($this->signedInUser->getKey(), $entry->actor_user_id);
            $this->assertNotNull($entry->after_json['reauthenticated_at']);
        }
    }

    public function test_a_workstation_holding_no_session_cannot_open_a_reauthentication_request(): void
    {
        $this->app['auth']->forgetGuards();

        $this->withHeader(SharedWorkstationSessionKey::HEADER, 'not-a-session-key')
            ->postJson(route('api.auth.shared-workstation-session.reauthentication-requests.store'))
            ->assertStatus(401);

        $this->assertSame(0, SharedWorkstationSignInRequest::query()->count());
    }
}
