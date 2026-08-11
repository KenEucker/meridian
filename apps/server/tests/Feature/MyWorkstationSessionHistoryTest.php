<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSession;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /api/me/workstation-sessions` — where this login has signed in at a
 * shared workstation (M18.71; AUTH-030; technical spec 13.3).
 *
 * A fact about the caller, so it needs no authority beyond a credential. The
 * property with teeth is the one at the bottom: the request names no subject,
 * so one person's history is invisible to another, and there is no role that
 * changes that.
 *
 * The other property worth holding is that "still signed in" is answered rather
 * than implied. A session that timed out with nobody watching has no `ended_at`
 * and is not live, and a client reading the timestamp alone would tell somebody
 * they are still signed in at a machine they left an hour ago.
 */
class MyWorkstationSessionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_callers_own_sessions_newest_first(): void
    {
        $user = User::factory()->create();
        $workstation = SharedWorkstation::factory()->create(['name' => 'Gate Kiosk']);

        SharedWorkstationSession::factory()->create([
            'user_id' => $user->getKey(),
            'shared_workstation_id' => $workstation->getKey(),
            'started_at' => now()->subDays(2),
            'last_activity_at' => now()->subDays(2),
        ]);
        SharedWorkstationSession::factory()->create([
            'user_id' => $user->getKey(),
            'shared_workstation_id' => $workstation->getKey(),
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subHour(),
        ]);

        $response = $this->history($user);

        $response->assertOk();
        $response->assertJsonCount(2, 'sessions');
        $response->assertJsonPath('sessions.0.workstation_name', 'Gate Kiosk');
        $this->assertGreaterThan(
            $response->json('sessions.1.started_at'),
            $response->json('sessions.0.started_at'),
        );
    }

    public function test_a_live_session_is_reported_as_active(): void
    {
        $user = User::factory()->create();

        SharedWorkstationSession::factory()->create([
            'user_id' => $user->getKey(),
            'started_at' => now()->subMinute(),
            'last_activity_at' => now(),
        ]);

        $this->history($user)->assertJsonPath('sessions.0.active', true);
    }

    /**
     * The five-minute timeout, which nothing writes down.
     *
     * `ended_at` is null because no request arrived to record an end, and the
     * session is still not live. This is the assertion that would fail if
     * anybody ever answered `active` from the column.
     */
    public function test_a_session_that_timed_out_unobserved_is_not_active(): void
    {
        $user = User::factory()->create();

        SharedWorkstationSession::factory()->idle()->create([
            'user_id' => $user->getKey(),
        ]);

        $response = $this->history($user);

        $response->assertJsonPath('sessions.0.ended_at', null);
        $response->assertJsonPath('sessions.0.active', false);
    }

    public function test_it_reports_how_a_session_ended(): void
    {
        $user = User::factory()->create();

        SharedWorkstationSession::factory()
            ->ended(SharedWorkstationSession::ENDED_SIGNED_OUT)
            ->create(['user_id' => $user->getKey()]);

        $response = $this->history($user);

        $response->assertJsonPath(
            'sessions.0.ended_reason',
            SharedWorkstationSession::ENDED_SIGNED_OUT,
        );
        $response->assertJsonPath('sessions.0.active', false);
    }

    /**
     * A session at a workstation that has since been revoked still happened,
     * and is still named.
     *
     * Revocation is what retiring a workstation actually is — the row survives,
     * because `shared_workstation_sessions` restricts its deletion and a
     * machine with history cannot be deleted at all. So the history keeps its
     * names, which is what the "was that me" question needs: "a workstation
     * you can no longer use" is a worse answer than "Gate Kiosk".
     */
    public function test_a_session_at_a_revoked_workstation_keeps_its_name(): void
    {
        $user = User::factory()->create();
        $workstation = SharedWorkstation::factory()->create([
            'name' => 'Retired Kiosk',
            'revoked_at' => now()->subDay(),
        ]);

        $session = SharedWorkstationSession::factory()->create([
            'user_id' => $user->getKey(),
            'shared_workstation_id' => $workstation->getKey(),
        ]);

        $response = $this->history($user);

        $response->assertJsonPath('sessions.0.id', (string) $session->getKey());
        $response->assertJsonPath('sessions.0.workstation_name', 'Retired Kiosk');
    }

    public function test_the_read_needs_a_session(): void
    {
        $this->getJson(route('api.me.workstation-sessions'))->assertStatus(401);
    }

    /**
     * The request names no subject, so it can only ever reach the caller.
     *
     * This is the assertion that would fail first if anybody added a `user_id`
     * parameter: where somebody's login has been is theirs to read, and no role
     * changes that.
     */
    public function test_one_users_history_does_not_reach_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        SharedWorkstationSession::factory()->create(['user_id' => $user->getKey()]);

        $this->history($other)->assertJsonCount(0, 'sessions');
    }

    private function history(User $user): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson(route('api.me.workstation-sessions'));
    }

    private function tokenFor(User $user): string
    {
        return app(ApiTokenIssuer::class)
            ->issue($user, Device::factory()->create())
            ->plainTextToken;
    }
}
