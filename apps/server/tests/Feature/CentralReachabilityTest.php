<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\User;
use App\Services\Node\CentralReachability;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodeSyncClient;
use App\Services\Node\NodeSyncException;
use App\Services\Node\NodeSyncOperationResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The second connectivity tier, as the node reports it (M18.52; technical spec
 * 9.6, 10.2; UI implementation contract 11.13, 16.1).
 *
 * A device knows whether its node answers. It cannot know whether that node's
 * own sync to central is working, because it never talks to central — so the
 * node says, on every API response. These tests cover both ends of that: what
 * the sync loop observes, and what a device is told.
 */
class CentralReachabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['meridian.event_mode.enabled' => false]);
        app(CentralReachability::class)->forget();
    }

    public function test_a_device_is_told_what_the_node_can_reach_on_every_response(): void
    {
        $this->asOnsiteInstall();
        app(CentralReachability::class)->recordUnreachable();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader(CentralReachability::HEADER, CentralReachability::UNREACHABLE);
    }

    /**
     * A refusal is an answer, and what the node can reach is as true of it as of
     * a success. A device that only learned the tier from requests that
     * succeeded would stop learning it exactly when things went wrong.
     */
    public function test_a_refused_request_carries_the_report_too(): void
    {
        $this->asOnsiteInstall();
        app(CentralReachability::class)->recordUnreachable();

        $this->getJson('/api/offline-read-set')
            ->assertUnauthorized()
            ->assertHeader(CentralReachability::HEADER, CentralReachability::UNREACHABLE);
    }

    public function test_an_authenticated_read_carries_the_report(): void
    {
        $this->asOnsiteInstall();
        app(CentralReachability::class)->recordReached();

        $this->actingAsClient(User::factory()->create())
            ->getJson('/api/offline-read-set')
            ->assertOk()
            ->assertHeader(CentralReachability::HEADER, CentralReachability::REACHABLE);
    }

    /**
     * Central is the sync target rather than a node with one beyond it, so there
     * is no second tier to report and a device reaching it is reaching
     * everything there is. The client turns this into plain `online`.
     */
    public function test_central_reports_that_the_question_does_not_apply(): void
    {
        Node::factory()->central()->create(['node_name' => 'juplaya.central']);

        $this->getJson('/api/health')
            ->assertHeader(CentralReachability::HEADER, CentralReachability::NOT_APPLICABLE);
    }

    public function test_an_install_with_no_node_reports_that_the_question_does_not_apply(): void
    {
        $this->getJson('/api/health')
            ->assertHeader(CentralReachability::HEADER, CentralReachability::NOT_APPLICABLE);
    }

    /**
     * A paired node that has not run a sync yet knows nothing about central and
     * says so. The device turns this into `local_node_reachable` — this node is
     * answering, and nothing is known beyond it — rather than into a claim that
     * central is fine.
     */
    public function test_a_paired_node_with_no_observation_reports_unknown(): void
    {
        $this->asOnsiteInstall();

        $this->assertSame(CentralReachability::UNKNOWN, app(CentralReachability::class)->state());
    }

    /**
     * Sync runs every minute, so an observation five minutes old is five missed
     * runs: the node has stopped finding out, and the last thing it found out is
     * no longer a claim it may make.
     */
    public function test_an_observation_stops_standing_once_it_is_stale(): void
    {
        $this->asOnsiteInstall();

        $reachability = app(CentralReachability::class);
        $reachability->recordReached(CarbonImmutable::now()->subMinutes(30));

        $this->assertSame(CentralReachability::UNKNOWN, $reachability->state());
    }

    public function test_an_unpaired_node_reports_unknown_rather_than_not_applicable(): void
    {
        // It is meant to reach central and does not, which is a thing to be
        // unknown about rather than a role with no central to reach.
        Node::factory()->onsite()->signing()->create(['node_name' => 'juplaya.2027.onsite']);

        $this->assertSame(CentralReachability::UNKNOWN, app(CentralReachability::class)->state());
    }

    // What the sync loop observes.

    public function test_an_exchange_that_never_completed_records_central_as_unreachable(): void
    {
        $this->asOnsiteInstall();

        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out.');
        });

        try {
            app(NodeSyncClient::class)->sync();
            $this->fail('An unreachable central node should be reported.');
        } catch (NodeSyncException $failure) {
            $this->assertSame(NodeSyncException::REASON_PEER_UNREACHABLE, $failure->reason);
        }

        $this->assertSame(
            CentralReachability::UNREACHABLE,
            app(CentralReachability::class)->state(),
        );
    }

    public function test_a_completed_exchange_records_central_as_reachable(): void
    {
        $this->asOnsiteInstall();
        app(CentralReachability::class)->recordUnreachable();

        Http::fake(fn () => Http::response($this->centralResponse()));

        app(NodeSyncClient::class)->sync();

        $this->assertSame(
            CentralReachability::REACHABLE,
            app(CentralReachability::class)->state(),
        );
    }

    /**
     * A central node that refused the exchange is a central node that is there.
     * `unreachable` means nothing was at the other end of the request, which is
     * the fact somebody reading "Central unreachable" is being told; a refusal is
     * a different problem and the `sync.node` diagnostic reports it with its
     * reason.
     */
    public function test_a_refused_exchange_still_proves_central_is_there(): void
    {
        $this->asOnsiteInstall();

        Http::fake(fn () => Http::response(['message' => 'Unknown node.'], 403));

        try {
            app(NodeSyncClient::class)->sync();
            $this->fail('A refused exchange should be reported.');
        } catch (NodeSyncException $failure) {
            $this->assertSame(NodeSyncException::REASON_PEER_REFUSED, $failure->reason);
        }

        $this->assertSame(
            CentralReachability::REACHABLE,
            app(CentralReachability::class)->state(),
        );
    }

    private function asOnsiteInstall(string $centralUrl = 'https://central.example.org'): void
    {
        $localNode = Node::factory()->onsite()->signing()->create([
            'node_name' => 'juplaya.2027.onsite',
        ]);

        $peerNode = Node::factory()->central()->remote()->create([
            'node_name' => 'juplaya.central',
            'public_key' => app(NodeKeyPairGenerator::class)->generate()['public_key'],
        ]);

        app(NodePairingState::class)->recordPairing(
            node: $localNode,
            centralNodeUrl: $centralUrl,
            centralNode: $peerNode,
            pairedAt: now(),
        );

        // The pairing status is cached for the life of a request; this test
        // establishes it after the container is already up.
        app(CentralReachability::class)->forget();
    }

    /**
     * @param  list<NodeSyncOperationResult>  $results
     * @return array<string, mixed>
     */
    private function centralResponse(array $results = []): array
    {
        return [
            'node_id' => (string) Str::uuid(),
            'received_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'results' => array_map(
                static fn (NodeSyncOperationResult $result): array => $result->toArray(),
                $results,
            ),
            'operations' => [],
            'acknowledged' => 0,
            'refusals_recorded' => 0,
        ];
    }
}
