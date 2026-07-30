<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\User;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use App\Services\Auth\SharedWorkstationLoginException;
use App\Services\Auth\SharedWorkstationSessionKey;
use App\Services\Auth\SharedWorkstationSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The session a shared-workstation login code establishes (M16.9).
 *
 * Source: AUTH-030; technical spec 13.3; data/API 12.3; kiosk and field hardware
 * UX guide 4.4. A code entry establishes a session at one trusted workstation
 * that ends five minutes after the last thing its user did or when they end it,
 * issues no personal device token, and takes its permissions entirely from the
 * active user. The workstation's pinned context frames the shell and grants
 * nothing.
 *
 * The code itself — how it is generated, hashed, scoped, and rate limited — is
 * M16.8 and is asserted in {@see SharedWorkstationLoginCodeTest}.
 */
class SharedWorkstationSessionTest extends TestCase
{
    use RefreshDatabase;

    private function sessions(): SharedWorkstationSessionService
    {
        return app(SharedWorkstationSessionService::class);
    }

    private function loginCodes(): SharedWorkstationLoginCodeService
    {
        return app(SharedWorkstationLoginCodeService::class);
    }

    /**
     * A trusted shared workstation pinned to a real event, which is the only
     * state a code can be scoped to (technical spec 13.1).
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

    /**
     * A code in somebody's hand, ready to be typed at this workstation.
     */
    private function codeFor(User $subject, SharedWorkstation $workstation): string
    {
        return $this->loginCodes()
            ->generateForUser($subject, User::factory()->create(), $workstation)
            ->plaintextCode;
    }

    private function enterCode(SharedWorkstation $workstation, string $code): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson(route('api.auth.shared-workstation-session.store'), [
            'shared_workstation_id' => $workstation->getKey(),
            'code' => $code,
        ]);
    }

    /**
     * A request as a Kiosk holding a session makes it.
     */
    private function asWorkstation(string $sessionKey): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader(SharedWorkstationSessionKey::HEADER, $sessionKey);
    }

    /*
    |--------------------------------------------------------------------------
    | Establishing a session (AUTH-030; technical spec 13.3)
    |--------------------------------------------------------------------------
    */

    public function test_entering_a_code_establishes_a_session_for_the_user_at_that_workstation(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create();

        $response = $this->enterCode($workstation, $this->codeFor($subject, $workstation));

        $response->assertStatus(201);
        $response->assertJsonPath('user.id', $subject->getKey());
        $response->assertJsonPath('shared_workstation.id', $workstation->getKey());
        $response->assertJsonPath('event_id', $workstation->event_id);

        $session = SharedWorkstationSession::query()->sole();

        $this->assertSame($subject->getKey(), $session->user_id);
        $this->assertSame($workstation->getKey(), $session->shared_workstation_id);
        // Scoped to the event the workstation is pinned to (technical spec 13.1),
        // not to anything the request asked for.
        $this->assertSame($workstation->event_id, $session->event_id);
        $this->assertTrue($session->isActive());

        // The key that came back is the key that was stored, and only its hash
        // is stored.
        $sessionKey = (string) $response->json('session_key');
        $this->assertNotSame('', $sessionKey);
        $this->assertSame(SharedWorkstationSessionKey::hash($sessionKey), $session->session_key_hash);
    }

    /**
     * "The active user is shown prominently at all times" (technical spec 13.3)
     * needs the name in the session response rather than in a second call a
     * Kiosk might not have made yet.
     */
    public function test_the_session_response_names_the_active_user_and_the_workstation(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create(['name' => 'Dana Okafor']);

        $response = $this->enterCode($workstation, $this->codeFor($subject, $workstation));

        $response->assertJsonPath('user.name', 'Dana Okafor');
        $response->assertJsonPath('shared_workstation.name', $workstation->name);
        $response->assertJsonPath('shared_workstation.organization_id', $workstation->organization_id);
        $response->assertJsonPath(
            'session.inactivity_timeout_seconds',
            SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES * 60,
        );
        $this->assertNotNull($response->json('session.expires_at'));
    }

    /**
     * AUTH-030: a successful code entry "shall not issue a personal device token
     * and shall not establish a trusted personal device session".
     */
    public function test_code_entry_issues_no_api_token_and_trusts_no_device(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create();

        $tokensBefore = ApiToken::query()->count();
        $trustsBefore = DeviceTrust::query()->count();

        $response = $this->enterCode($workstation, $this->codeFor($subject, $workstation));

        $response->assertStatus(201);

        $this->assertSame($tokensBefore, ApiToken::query()->count());
        $this->assertSame($trustsBefore, DeviceTrust::query()->count());

        // Nor does the response hand a client something it could use as one.
        $response->assertJsonMissingPath('token');
        $response->assertJsonMissingPath('access_token');
        $response->assertJsonMissingPath('plainTextToken');
    }

    /**
     * AUTH-024: bearer token lifetime is "independent of the shared-workstation
     * session timeout". A session key is not a bearer token and is refused as
     * one.
     */
    public function test_a_session_key_is_not_accepted_as_a_bearer_token(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$sessionKey)
            ->getJson(route('api.me'))
            ->assertUnauthorized();
    }

    public function test_an_untrusted_workstation_cannot_establish_a_session(): void
    {
        $event = Event::factory()->create();
        $workstation = SharedWorkstation::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);

        $code = $this->codeFor(User::factory()->create(), $workstation);

        $workstation->forceFill(['trusted' => false])->save();

        $response = $this->enterCode($workstation->fresh(), $code);

        $response->assertStatus(403);
        $response->assertJsonPath('reason', SharedWorkstationLoginException::REASON_WORKSTATION_UNTRUSTED);
        $this->assertSame(0, SharedWorkstationSession::query()->count());
    }

    public function test_a_wrong_code_establishes_nothing_and_says_only_that_it_was_not_accepted(): void
    {
        $workstation = $this->workstation();

        $response = $this->enterCode($workstation, 'WRONGCOD');

        $response->assertStatus(401);
        $response->assertJsonPath('reason', SharedWorkstationLoginException::REASON_INVALID_CODE);

        // Kiosk guide 4.2: a failed code does not say whether the code exists,
        // whether it expired, or whom it belongs to.
        $message = (string) $response->json('message');
        foreach (['expired', 'revoked', 'used', 'unknown user'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message);
        }

        $this->assertSame(0, SharedWorkstationSession::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Authority comes from the active user (technical spec 13.3)
    |--------------------------------------------------------------------------
    */

    /**
     * A session key authenticates `GET /api/me`, and the document it returns is
     * the active user's — the workstation contributes no roles of its own.
     */
    public function test_a_session_resolves_the_active_users_own_session_document(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create();

        $sessionKey = $this->establish($workstation, $subject);

        $response = $this->asWorkstation($sessionKey)->getJson(route('api.me'));

        $response->assertOk();
        $response->assertJsonPath('user.id', $subject->getKey());
    }

    /**
     * A session key also authenticates the operational endpoints, which is the
     * whole point of a shared workstation (M16.11; technical spec 13.3).
     *
     * The command routes used to sit behind the `local.field` shared token and
     * now sit behind `auth:sanctum,workstation`. A Kiosk holds no bearer token
     * (AUTH-030), so without the second guard a workstation could sign somebody
     * in and then refuse every check-in they tried to record. The empty command
     * is refused on its contents rather than on its credential, which is the
     * distinction being asserted.
     */
    public function test_a_session_reaches_the_command_endpoints(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->app['auth']->forgetGuards();

        $this->asWorkstation($sessionKey)
            ->postJson('/api/commands/check-in-staff', [])
            ->assertUnprocessable();
    }

    public function test_a_session_whose_user_is_disabled_stops_authenticating(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create();

        $sessionKey = $this->establish($workstation, $subject);

        $subject->forceFill(['disabled_at' => now()])->save();

        $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertUnauthorized();
    }

    public function test_an_unknown_session_key_authenticates_nothing(): void
    {
        $this->asWorkstation(SharedWorkstationSessionKey::generate())
            ->getJson(route('api.me'))
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Inactivity timeout (technical spec 13.3; data/API 12.3)
    |--------------------------------------------------------------------------
    */

    public function test_a_session_times_out_after_five_minutes_of_inactivity(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->travel(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES)->minutes();

        $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertUnauthorized();

        $session = SharedWorkstationSession::query()->sole();

        $this->assertFalse($session->isActive());
        $this->assertSame(SharedWorkstationSession::ENDED_TIMED_OUT, $session->ended_reason);
    }

    public function test_activity_slides_the_inactivity_window(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        // Four minutes in, then another four: past the original deadline, but
        // never five minutes without anything happening.
        foreach ([4, 4, 4] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertOk();
        }

        $this->assertNull(SharedWorkstationSession::query()->sole()->ended_at);
    }

    /**
     * Reading the session is the "continue" action behind the timeout warning
     * the kiosk guide asks for, so it has to be activity like anything else.
     */
    public function test_reading_the_session_is_activity_and_reports_the_new_deadline(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $before = SharedWorkstationSession::query()->sole()->expiresAt();

        $this->travel(4)->minutes();

        $response = $this->asWorkstation($sessionKey)
            ->getJson(route('api.auth.shared-workstation-session.show'));

        $response->assertOk();

        $this->assertTrue(SharedWorkstationSession::query()->sole()->expiresAt()->gt($before));
        $this->assertSame(
            SharedWorkstationSession::query()->sole()->expiresAt()->toIso8601String(),
            $response->json('session.expires_at'),
        );
    }

    /**
     * A timeout is stamped at the moment it happened rather than at the moment
     * somebody noticed, so an unattended workstation does not read as having had
     * a user signed in all night.
     */
    public function test_a_timeout_is_recorded_at_the_moment_the_session_expired(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $expiredAt = SharedWorkstationSession::query()->sole()->expiresAt();

        $this->travel(3)->hours();

        $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertUnauthorized();

        $this->assertSame(
            $expiredAt->toIso8601String(),
            SharedWorkstationSession::query()->sole()->ended_at?->toIso8601String(),
        );
    }

    /**
     * A timed-out session is over even before anything has looked at it, because
     * expiry is derived from the last activity rather than stamped by a sweep.
     */
    public function test_a_session_is_inactive_once_its_window_closes_whether_or_not_anyone_has_looked(): void
    {
        $workstation = $this->workstation();
        $this->establish($workstation, User::factory()->create());

        $this->travel(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES)->minutes();

        $this->assertFalse(SharedWorkstationSession::query()->sole()->isActive());
        $this->assertNull($this->sessions()->activeSessionFor($workstation->fresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | Explicit end and user switching (technical spec 13.3)
    |--------------------------------------------------------------------------
    */

    public function test_a_user_ends_their_own_session(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->asWorkstation($sessionKey)
            ->deleteJson(route('api.auth.shared-workstation-session.destroy'))
            ->assertOk();

        $session = SharedWorkstationSession::query()->sole();

        $this->assertSame(SharedWorkstationSession::ENDED_SIGNED_OUT, $session->ended_reason);
        $this->assertNotNull($session->ended_at);

        // The key stops working the moment the session ends.
        $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertUnauthorized();
    }

    /**
     * Technical spec 13.3 requires an explicit end before switching users, and
     * the Kiosk shell is where that is enforced — it offers no code entry while
     * a session is live.
     *
     * The node deliberately does not refuse a second entry, because a Kiosk that
     * crashed still holds a session it can no longer present and refusing would
     * lock the workstation out for five minutes. It supersedes, and records that
     * it did, so a handover that skipped the rule is visible afterwards.
     */
    public function test_a_second_code_entry_supersedes_a_live_session_and_records_it(): void
    {
        $workstation = $this->workstation();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $firstKey = $this->establish($workstation, $first);
        $secondKey = $this->establish($workstation, $second);

        $superseded = SharedWorkstationSession::query()->where('user_id', $first->getKey())->sole();

        $this->assertSame(SharedWorkstationSession::ENDED_SUPERSEDED, $superseded->ended_reason);
        $this->assertNotSame($firstKey, $secondKey);

        // Only the newer session authenticates, so the first user's credential
        // does not survive the handover.
        $this->asWorkstation($firstKey)->getJson(route('api.me'))->assertUnauthorized();
        $this->asWorkstation($secondKey)->getJson(route('api.me'))->assertOk();

        $this->assertNull(
            SharedWorkstationSession::query()->where('user_id', $second->getKey())->sole()->ended_at,
        );
    }

    /**
     * A workstation whose previous session had already timed out records that,
     * not a supersession — the difference is whether somebody signed in over a
     * live session.
     */
    public function test_starting_over_a_timed_out_session_records_a_timeout_not_a_supersession(): void
    {
        $workstation = $this->workstation();
        $first = User::factory()->create();

        $this->establish($workstation, $first);

        $this->travel(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES + 1)->minutes();

        $this->establish($workstation, User::factory()->create());

        $this->assertSame(
            SharedWorkstationSession::ENDED_TIMED_OUT,
            SharedWorkstationSession::query()->where('user_id', $first->getKey())->sole()->ended_reason,
        );
    }

    /**
     * A session at one workstation is not touched by a session starting at
     * another, so two kiosks at one event do not sign each other out.
     */
    public function test_a_session_at_another_workstation_is_left_alone(): void
    {
        $atGate = $this->workstation();
        $atMedical = $this->workstation();

        $gateKey = $this->establish($atGate, User::factory()->create());
        $this->establish($atMedical, User::factory()->create());

        $this->asWorkstation($gateKey)->getJson(route('api.me'))->assertOk();
    }

    /**
     * Ending a session that has already gone is not an error to report to
     * somebody walking away from a machine.
     */
    public function test_ending_an_already_timed_out_session_is_answered_as_ended(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->travel(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES + 1)->minutes();

        // The guard refuses first, because a timed-out session is not a session.
        $this->asWorkstation($sessionKey)
            ->deleteJson(route('api.auth.shared-workstation-session.destroy'))
            ->assertUnauthorized();

        $this->assertSame(
            SharedWorkstationSession::ENDED_TIMED_OUT,
            SharedWorkstationSession::query()->sole()->ended_reason,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lock on restart (technical spec 13.3)
    |--------------------------------------------------------------------------
    */

    /**
     * "If the Electron app restarts, the shared workstation session locks
     * immediately."
     *
     * The server half of that: the raw key is returned once and is nowhere
     * recoverable, so a Kiosk that lost it in memory cannot get back in. The
     * client half — never persisting the key — is asserted in the Kiosk client
     * specs.
     */
    public function test_a_session_key_is_returned_once_and_is_not_recoverable(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create();

        $response = $this->enterCode($workstation, $this->codeFor($subject, $workstation));
        $sessionKey = (string) $response->json('session_key');

        // Reading the session back tells a Kiosk everything about it except the
        // credential, which is the point.
        $reread = $this->asWorkstation($sessionKey)
            ->getJson(route('api.auth.shared-workstation-session.show'));

        $reread->assertOk();
        $reread->assertJsonMissingPath('session_key');

        $stored = (string) json_encode(SharedWorkstationSession::query()->sole()->getAttributes());
        $this->assertStringNotContainsString($sessionKey, $stored);
    }

    public function test_no_raw_session_key_reaches_the_database_an_audit_entry_or_the_log(): void
    {
        Log::spy();

        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        // Reach the rest of a session's audited life before asserting.
        $this->asWorkstation($sessionKey)
            ->deleteJson(route('api.auth.shared-workstation-session.destroy'))
            ->assertOk();

        $session = SharedWorkstationSession::query()->sole();
        $audited = AuditEvent::query()
            ->get()
            ->map(fn (AuditEvent $event): string => (string) json_encode($event->getAttributes()))
            ->implode("\n");

        $this->assertNotSame('', $audited);

        foreach ([(string) json_encode($session->getAttributes()), $audited] as $haystack) {
            $this->assertStringNotContainsString($sessionKey, $haystack);
        }

        $this->assertStringNotContainsString($session->session_key_hash, $audited);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * Only a keyed hash, namespaced away from every other credential hash, so a
     * hash lifted from one table cannot be replayed against another.
     */
    public function test_only_a_keyed_hash_of_a_session_key_is_stored(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $session = SharedWorkstationSession::query()->sole();

        $this->assertNotSame($sessionKey, $session->session_key_hash);
        $this->assertSame(SharedWorkstationSessionKey::hash($sessionKey), $session->session_key_hash);
        $this->assertNotSame(hash('sha256', $sessionKey), $session->session_key_hash);
    }

    /*
    |--------------------------------------------------------------------------
    | Audit (data/API section 8; technical spec 13.3)
    |--------------------------------------------------------------------------
    */

    public function test_ending_a_session_is_audited_with_its_reason_and_scope(): void
    {
        $workstation = $this->workstation();
        $subject = User::factory()->create();

        $sessionKey = $this->establish($workstation, $subject);

        $this->asWorkstation($sessionKey)
            ->deleteJson(route('api.auth.shared-workstation-session.destroy'))
            ->assertOk();

        $audit = AuditEvent::query()
            ->where('action', SharedWorkstationSessionService::AUDIT_ENDED)
            ->sole();

        $session = SharedWorkstationSession::query()->sole();

        $this->assertSame((string) $session->getKey(), $audit->entity_id);
        $this->assertSame($subject->getKey(), $audit->actor_user_id);
        $this->assertSame((string) $workstation->device_id, $audit->actor_device_id);
        $this->assertSame($workstation->organization_id, $audit->organization_id);
        $this->assertSame($workstation->event_id, $audit->event_id);
        $this->assertSame(SharedWorkstationSession::ENDED_SIGNED_OUT, $audit->reason);
    }

    public function test_a_timeout_is_audited_as_a_timeout(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->travel(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES + 1)->minutes();

        $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertUnauthorized();

        $audit = AuditEvent::query()
            ->where('action', SharedWorkstationSessionService::AUDIT_ENDED)
            ->sole();

        $this->assertSame(SharedWorkstationSession::ENDED_TIMED_OUT, $audit->reason);
    }

    /**
     * A session is never audited as ending twice, however many requests arrive
     * after it went.
     */
    public function test_a_session_ends_once(): void
    {
        $workstation = $this->workstation();
        $sessionKey = $this->establish($workstation, User::factory()->create());

        $this->travel(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES + 1)->minutes();

        foreach (range(1, 3) as $ignored) {
            $this->asWorkstation($sessionKey)->getJson(route('api.me'))->assertUnauthorized();
        }

        $this->assertSame(1, AuditEvent::query()
            ->where('action', SharedWorkstationSessionService::AUDIT_ENDED)
            ->count());
    }

    /**
     * Establish a session at a workstation and answer its raw key.
     */
    private function establish(SharedWorkstation $workstation, User $subject): string
    {
        return (string) $this
            ->enterCode($workstation, $this->codeFor($subject, $workstation))
            ->assertStatus(201)
            ->json('session_key');
    }
}
