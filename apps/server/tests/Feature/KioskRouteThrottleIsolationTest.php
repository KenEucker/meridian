<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The Kiosk's polling routes hold buckets of their own (M18.67; AUTH-037).
 *
 * Laravel's `throttle:n,1` resolves an unauthenticated caller's signature to
 * `sha1(domain|ip)` — the route is not part of the key — so every
 * unauthenticated route on a node shares one counter per client address, and
 * the route with the lowest limit refuses first.
 *
 * A locked Kiosk polls for its grant every few seconds by design. On the shared
 * signature those polls drained the bucket and the node then refused *login*,
 * which is the one request a workstation must always be able to make: a person
 * entering their first code was told "Too Many Attempts". These tests are the
 * proof that the buckets are separate, run against the same sequence that
 * produced the failure.
 */
class KioskRouteThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function workstation(): SharedWorkstation
    {
        $event = Event::factory()->create();

        return SharedWorkstation::factory()->create([
            'organization_id' => $event->organization_id,
            'event_id' => $event->getKey(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('workstation:');
    }

    public function test_polling_for_a_grant_does_not_starve_the_login_route(): void
    {
        $workstation = $this->workstation();
        $user = User::factory()->create();

        $issued = app(SharedWorkstationLoginCodeService::class)
            ->generateForUser($user, $user, $workstation);

        // Well past the login route's own limit, on the route a waiting Kiosk
        // hammers. Every one of these used to spend the login's budget.
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $this->postJson(route('api.kiosk.workstations.sign-in-requests.store', $workstation));
        }

        $response = $this->postJson(route('api.auth.shared-workstation-session.store'), [
            'shared_workstation_id' => $workstation->getKey(),
            'code' => $issued->plaintextCode,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame(429, $response->getStatusCode());
    }

    public function test_polling_for_a_grant_does_not_starve_the_magic_link_route(): void
    {
        $workstation = $this->workstation();

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $this->postJson(route('api.kiosk.workstations.sign-in-requests.store', $workstation));
        }

        // A person signing in to their phone beside the Kiosk is a different
        // client with a different job, and shares nothing with it.
        $this->postJson(route('api.auth.magic-link.store'), [
            'email' => User::factory()->create()->email,
        ])->assertStatus(202);
    }

    public function test_one_busy_workstation_does_not_starve_another(): void
    {
        $busy = $this->workstation();
        $quiet = $this->workstation();

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $this->postJson(route('api.kiosk.workstations.sign-in-requests.store', $busy));
        }

        // Keyed per workstation, so the machine at the next desk is unaffected.
        $this->postJson(route('api.kiosk.workstations.sign-in-requests.store', $quiet))
            ->assertStatus(201);
    }

    public function test_the_open_route_still_refuses_a_workstation_that_will_not_stop(): void
    {
        $workstation = $this->workstation();

        $statuses = [];

        for ($attempt = 0; $attempt < 45; $attempt++) {
            $statuses[] = $this->postJson(
                route('api.kiosk.workstations.sign-in-requests.store', $workstation),
            )->getStatusCode();
        }

        // The limit is its own rather than absent: opening is still bounded
        // (AUTH-037), and the domain limit refuses well before the route one.
        $this->assertContains(429, $statuses);
    }
}
