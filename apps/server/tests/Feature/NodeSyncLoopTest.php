<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\NodeOperationEnvelope;
use App\Services\Node\NodeOperationRecorder;
use App\Services\Node\NodeOperationRejectedException;
use App\Services\Node\NodeOperationSigner;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\NodeSyncClient;
use App\Services\Node\NodeSyncException;
use App\Services\Node\NodeSyncHealth;
use App\Services\Node\NodeSyncOperationResult;
use App\Services\Node\NodeSyncRequest;
use App\Services\Node\NodeSyncService;
use App\Services\Node\SignedNodeOperation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * M12.5 bidirectional sync loop: operations move central to on-site and on-site
 * to central (technical spec 10.1, 10.2).
 *
 * Both halves are covered against their real surfaces. The answering half runs
 * over `POST /api/node-sync` with this install acting as central and the caller
 * simulated the way a real peer behaves — its own keypair, signing its own
 * exchange, known here only as a paired peer `nodes` record. The initiating half
 * runs `NodeSyncClient` with this install acting as on-site and central's
 * responses faked at the HTTP boundary.
 *
 * The two halves are deliberately not run against each other in one process:
 * two nodes sharing one database would have to be the same install, and the
 * thing under test is precisely what happens between two installs.
 */
class NodeSyncLoopTest extends TestCase
{
    use RefreshDatabase;

    private NodeSignatureAlgorithm $algorithm;

    private NodeOperationSigner $signer;

    private NodeOperationApplierRegistry $appliers;

    private User $actor;

    private Node $localNode;

    private Node $peerNode;

    /** @var array{public_key: string, private_key: string} */
    private array $peerKeys;

    protected function setUp(): void
    {
        parent::setUp();

        // The event-mode HTTPS safeguard has its own coverage; tests that need
        // it turn it on explicitly.
        config(['meridian.event_mode.enabled' => false]);

        $this->algorithm = app(NodeSignatureAlgorithm::class);
        $this->signer = app(NodeOperationSigner::class);
        $this->appliers = app(NodeOperationApplierRegistry::class);
        $this->actor = User::factory()->create();
    }

    // Answering node: POST /api/node-sync

    /**
     * The behavior the whole task exists for: one exchange carries this node's
     * operations out and the peer's operations in.
     */
    public function test_one_exchange_moves_operations_in_both_directions(): void
    {
        $this->asCentralInstall();
        $applier = $this->registerApplier();

        $ours = $this->queueLocalOperation('close', 'incident');
        $theirs = $this->peerEnvelope(['operation_type' => 'submit', 'entity_type' => 'field_report']);

        $response = $this->exchange([$theirs]);

        $response->assertOk();

        // Their operation arrived, was stored, and was applied here.
        $response->assertJsonPath('results.0.uuid', $theirs->uuid());
        $response->assertJsonPath('results.0.outcome', NodeSyncOperationResult::OUTCOME_STORED);
        $this->assertSame(1, $applier->applied);
        $this->assertDatabaseHas('node_operations', [
            'uuid' => $theirs->uuid(),
            'status' => NodeOperation::STATUS_APPLIED,
        ]);

        // Ours went back in the same round trip, still queued until the peer
        // says it holds it.
        $response->assertJsonCount(1, 'operations');
        $response->assertJsonPath('operations.0.uuid', $ours->uuid);
        $response->assertJsonPath('operations.0.operation_type', 'close');
        $this->assertSame(NodeOperation::STATUS_PENDING, $ours->fresh()->status);
        $this->assertNull($ours->fresh()->sent_at);
    }

    public function test_an_operation_the_peer_pushed_carries_its_origin_content(): void
    {
        $this->asCentralInstall();
        $this->registerApplier();

        $envelope = $this->peerEnvelope(['payload_json' => ['note' => 'from the field']]);

        $this->exchange([$envelope])->assertOk();

        $stored = NodeOperation::query()->where('uuid', $envelope->uuid())->firstOrFail();

        $this->assertSame((string) $this->peerNode->getKey(), $stored->origin_node_id);
        $this->assertSame($envelope->signature, $stored->signature);
        $this->assertSame(['note' => 'from the field'], $stored->payload_json);
        $this->assertTrue($this->signer->verify($stored));
        $this->assertNotNull($stored->received_at);
        $this->assertNull($stored->sent_at);
    }

    public function test_redelivering_an_operation_reports_a_duplicate_and_stores_it_once(): void
    {
        $this->asCentralInstall();
        $applier = $this->registerApplier();

        $envelope = $this->peerEnvelope();

        $this->exchange([$envelope])
            ->assertJsonPath('results.0.outcome', NodeSyncOperationResult::OUTCOME_STORED);

        $this->exchange([$envelope])
            ->assertJsonPath('results.0.outcome', NodeSyncOperationResult::OUTCOME_DUPLICATE);

        $this->assertDatabaseCount('node_operations', 1);
        $this->assertSame(1, $applier->applied);
    }

    /**
     * Unresolved problems must not block unrelated sync (technical spec 10.3).
     */
    public function test_a_refused_operation_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->asCentralInstall();
        $this->registerApplier();

        $good = $this->peerEnvelope();
        $unknownActor = $this->peerEnvelope(['actor_user_id' => (string) Str::uuid()]);
        $alsoGood = $this->peerEnvelope();

        $response = $this->exchange([$good, $unknownActor, $alsoGood]);

        $response->assertOk();
        $response->assertJsonPath('results.0.outcome', NodeSyncOperationResult::OUTCOME_STORED);
        $response->assertJsonPath('results.1.outcome', NodeSyncOperationResult::OUTCOME_REFUSED);
        $response->assertJsonPath(
            'results.1.reason_code',
            NodeOperationRejectedException::REASON_UNKNOWN_ACTOR_USER,
        );
        $response->assertJsonPath('results.2.outcome', NodeSyncOperationResult::OUTCOME_STORED);

        $this->assertDatabaseCount('node_operations', 2);
        $this->assertDatabaseMissing('node_operations', ['uuid' => $unknownActor->uuid()]);
    }

    public function test_acknowledged_operations_stop_being_offered(): void
    {
        $this->asCentralInstall();

        $operation = $this->queueLocalOperation();

        $this->exchange()->assertJsonCount(1, 'operations');

        $response = $this->exchange(acknowledged: [$operation->uuid]);

        $response->assertJsonPath('acknowledged', 1);
        $response->assertJsonCount(0, 'operations');

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_SENT, $operation->status);
        $this->assertNotNull($operation->sent_at);
    }

    /**
     * An operation the peer will never accept is parked rather than offered on
     * every run forever.
     */
    public function test_an_operation_the_peer_refuses_is_parked_as_failed(): void
    {
        $this->asCentralInstall();

        $operation = $this->queueLocalOperation();

        $response = $this->exchange(refused: [
            new NodeSyncOperationResult(
                uuid: $operation->uuid,
                outcome: NodeSyncOperationResult::OUTCOME_REFUSED,
                reasonCode: NodeOperationRejectedException::REASON_UNKNOWN_ACTOR_USER,
                detail: 'The node operation acting user is not known to this node.',
            ),
        ]);

        $response->assertJsonPath('refusals_recorded', 1);
        $response->assertJsonCount(0, 'operations');

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->status);
        $this->assertSame(1, $operation->retry_count);
        $this->assertStringContainsString(
            NodeOperationRejectedException::REASON_UNKNOWN_ACTOR_USER,
            (string) $operation->failure_reason,
        );
    }

    public function test_a_peer_cannot_settle_operations_it_did_not_receive_from_this_node(): void
    {
        $this->asCentralInstall();

        // An operation this node received from the peer is not this node's to
        // mark delivered, and the peer saying so must not change it.
        $envelope = $this->peerEnvelope();
        $this->exchange([$envelope])->assertOk();

        $this->exchange(acknowledged: [$envelope->uuid()])
            ->assertJsonPath('acknowledged', 0);

        $this->assertNull(
            NodeOperation::query()->where('uuid', $envelope->uuid())->firstOrFail()->sent_at,
        );
    }

    public function test_operations_received_from_the_peer_are_not_offered_back_to_it(): void
    {
        $this->asCentralInstall();
        $this->registerApplier();

        $envelope = $this->peerEnvelope();

        $this->exchange([$envelope])->assertJsonCount(0, 'operations');
        $this->exchange()->assertJsonCount(0, 'operations');
    }

    public function test_an_operation_addressed_to_another_node_is_not_offered(): void
    {
        $this->asCentralInstall();

        $elsewhere = Node::factory()->onsite()->remote()->create();

        $this->queueLocalOperation(targetNode: $elsewhere);
        $addressed = $this->queueLocalOperation(targetNode: $this->peerNode);

        $response = $this->exchange();

        $response->assertJsonCount(1, 'operations');
        $response->assertJsonPath('operations.0.uuid', $addressed->uuid);
    }

    public function test_an_exchange_offers_at_most_one_batch(): void
    {
        $this->asCentralInstall();
        config(['meridian.node.sync.batch_size' => 2]);

        $this->queueLocalOperation();
        $this->queueLocalOperation();
        $this->queueLocalOperation();

        $this->exchange()->assertJsonCount(2, 'operations');
    }

    public function test_an_exchange_from_an_unknown_node_is_refused_and_audited(): void
    {
        $this->asCentralInstall();

        $stranger = Node::factory()->onsite()->remote()->make(['id' => (string) Str::uuid()]);

        $request = NodeSyncRequest::create($stranger);
        $signed = $request->signedWith(
            $this->algorithm->sign($request->canonicalPayload(), $this->peerKeys['private_key']),
        );

        $response = $this->postJson(route('api.node-sync.store'), $signed->toArray());

        $response->assertStatus(401);
        $response->assertJsonPath('reason', NodeSyncException::REASON_UNKNOWN_SOURCE_NODE);
        $this->assertRefusalAudited((string) $stranger->getKey(), NodeSyncException::REASON_UNKNOWN_SOURCE_NODE);
    }

    public function test_an_exchange_from_a_revoked_peer_is_refused(): void
    {
        $this->asCentralInstall();
        $this->queueLocalOperation();

        $this->peerNode->forceFill(['revoked_at' => now()])->save();

        $response = $this->postJson(route('api.node-sync.store'), $this->signedRequest());

        $response->assertStatus(401);
        $response->assertJsonPath('reason', NodeSyncException::REASON_SOURCE_NODE_NOT_ACCEPTED);
        $this->assertRefusalAudited(
            (string) $this->peerNode->getKey(),
            NodeSyncException::REASON_SOURCE_NODE_NOT_ACCEPTED,
        );
    }

    public function test_an_exchange_from_a_node_that_never_paired_is_refused(): void
    {
        $this->asCentralInstall();

        $this->peerNode->forceFill(['paired_at' => null])->save();

        $this->postJson(route('api.node-sync.store'), $this->signedRequest())
            ->assertStatus(401)
            ->assertJsonPath('reason', NodeSyncException::REASON_SOURCE_NODE_NOT_ACCEPTED);
    }

    /**
     * The response hands operations back, so an unverifiable caller must not be
     * able to pull this node's queue.
     */
    public function test_an_unverifiable_exchange_signature_is_refused_and_returns_nothing(): void
    {
        $this->asCentralInstall();
        $this->queueLocalOperation();

        $tampered = $this->signedRequest();
        $tampered['signature'] = base64_encode(random_bytes(64));

        $response = $this->postJson(route('api.node-sync.store'), $tampered);

        $response->assertStatus(401);
        $response->assertJsonPath('reason', NodeSyncException::REASON_SIGNATURE_REJECTED);
        $response->assertJsonMissingPath('operations');
        $this->assertRefusalAudited(
            (string) $this->peerNode->getKey(),
            NodeSyncException::REASON_SIGNATURE_REJECTED,
        );
    }

    public function test_an_exchange_signed_by_another_node_is_refused(): void
    {
        $this->asCentralInstall();

        $otherKeys = app(NodeKeyPairGenerator::class)->generate();

        $this->postJson(route('api.node-sync.store'), $this->signedRequest(keys: $otherKeys))
            ->assertStatus(401)
            ->assertJsonPath('reason', NodeSyncException::REASON_SIGNATURE_REJECTED);
    }

    public function test_rewriting_a_signed_exchange_field_is_refused(): void
    {
        $this->asCentralInstall();

        $rewritten = $this->signedRequest(acknowledged: [(string) Str::uuid()]);
        $rewritten['acknowledged'] = [(string) Str::uuid()];

        $this->postJson(route('api.node-sync.store'), $rewritten)
            ->assertStatus(401)
            ->assertJsonPath('reason', NodeSyncException::REASON_SIGNATURE_REJECTED);
    }

    public function test_an_exchange_outside_the_clock_window_is_refused(): void
    {
        $this->asCentralInstall();

        config(['meridian.node.sync.max_request_age_seconds' => 60]);

        $stale = $this->signedRequest(sentAt: CarbonImmutable::now()->utc()->subMinutes(10));
        $this->postJson(route('api.node-sync.store'), $stale)
            ->assertStatus(401)
            ->assertJsonPath('reason', NodeSyncException::REASON_STALE_REQUEST);

        $ahead = $this->signedRequest(sentAt: CarbonImmutable::now()->utc()->addMinutes(10));
        $this->postJson(route('api.node-sync.store'), $ahead)
            ->assertStatus(401)
            ->assertJsonPath('reason', NodeSyncException::REASON_STALE_REQUEST);
    }

    public function test_a_malformed_exchange_is_refused_without_storing_anything(): void
    {
        $this->asCentralInstall();

        $malformed = $this->signedRequest([$this->peerEnvelope()]);
        unset($malformed['operations'][0]['uuid']);

        $this->postJson(route('api.node-sync.store'), $malformed)
            ->assertStatus(422)
            ->assertJsonPath('reason', NodeSyncException::REASON_MALFORMED_REQUEST);

        $this->postJson(route('api.node-sync.store'), [])
            ->assertStatus(422)
            ->assertJsonPath('reason', NodeSyncException::REASON_MALFORMED_REQUEST);

        $this->assertDatabaseCount('node_operations', 0);
    }

    // Initiating node: NodeSyncClient

    public function test_a_run_pushes_queued_operations_and_applies_what_central_returns(): void
    {
        $this->asOnsiteInstall();
        $applier = $this->registerApplier();

        $ours = $this->queueLocalOperation('submit', 'field_report');
        $theirs = $this->peerEnvelope(['operation_type' => 'publish', 'entity_type' => 'policy_document']);

        $this->fakeCentral([
            $this->centralResponse(
                results: [NodeSyncOperationResult::stored($ours->uuid)],
                operations: [$theirs],
            ),
            $this->centralResponse(),
        ]);

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(1, $run->pushed);
        $this->assertSame(1, $run->accepted);
        $this->assertSame(1, $run->pulled);
        $this->assertSame(1, $run->applied);
        $this->assertSame(0, $run->refusedHere);
        $this->assertSame(1, $applier->applied);

        $ours->refresh();

        $this->assertSame(NodeOperation::STATUS_SENT, $ours->status);
        $this->assertNotNull($ours->sent_at);

        $this->assertDatabaseHas('node_operations', [
            'uuid' => $theirs->uuid(),
            'status' => NodeOperation::STATUS_APPLIED,
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://central.example.org/api/node-sync');
    }

    public function test_a_run_acknowledges_pulled_operations_on_the_next_exchange(): void
    {
        $this->asOnsiteInstall();
        $this->registerApplier();

        $theirs = $this->peerEnvelope();

        $this->fakeCentral([
            $this->centralResponse(operations: [$theirs]),
            $this->centralResponse(),
        ]);

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(2, $run->exchanges);

        $bodies = $this->sentBodies();

        $this->assertSame([], $bodies[0]['acknowledged']);
        $this->assertSame([$theirs->uuid()], $bodies[1]['acknowledged']);
    }

    public function test_a_backlog_larger_than_one_batch_drains_over_several_exchanges(): void
    {
        $this->asOnsiteInstall();
        config(['meridian.node.sync.batch_size' => 2]);

        $queued = collect(range(1, 5))->map(fn (): NodeOperation => $this->queueLocalOperation());

        $this->fakeCentral(fn (array $body): array => $this->centralResponse(
            results: array_map(
                static fn (array $operation): NodeSyncOperationResult => NodeSyncOperationResult::stored($operation['uuid']),
                $body['operations'],
            ),
        ));

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(5, $run->pushed);
        $this->assertSame(5, $run->accepted);
        $this->assertSame(3, $run->exchanges);

        foreach ($queued as $operation) {
            $this->assertSame(NodeOperation::STATUS_SENT, $operation->fresh()->status);
        }
    }

    /**
     * When the internet disappears, on-site queues operations and pushes them
     * later (technical spec 10.2). Nothing is marked delivered.
     */
    public function test_an_unreachable_central_leaves_operations_queued(): void
    {
        $this->asOnsiteInstall();

        $operation = $this->queueLocalOperation();
        $online = false;

        Http::fake(function () use (&$online, $operation) {
            if (! $online) {
                throw new ConnectionException('Connection timed out.');
            }

            return Http::response($this->centralResponse(
                results: [NodeSyncOperationResult::stored($operation->uuid)],
            ));
        });

        try {
            app(NodeSyncClient::class)->sync();
            $this->fail('An unreachable central node should be reported.');
        } catch (NodeSyncException $failure) {
            $this->assertSame(NodeSyncException::REASON_PEER_UNREACHABLE, $failure->reason);
        }

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_PENDING, $operation->status);
        $this->assertNull($operation->sent_at);
        $this->assertSame(0, $operation->retry_count);

        // The same operation goes out once the peer is reachable again.
        $online = true;

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(1, $run->accepted);
        $this->assertSame(NodeOperation::STATUS_SENT, $operation->fresh()->status);
    }

    public function test_an_operation_central_refuses_is_parked_and_not_offered_again(): void
    {
        $this->asOnsiteInstall();

        $operation = $this->queueLocalOperation();

        $this->fakeCentral([
            $this->centralResponse(results: [
                new NodeSyncOperationResult(
                    uuid: $operation->uuid,
                    outcome: NodeSyncOperationResult::OUTCOME_REFUSED,
                    reasonCode: NodeOperationRejectedException::REASON_SIGNATURE_REJECTED,
                    detail: 'The node signature on this node operation could not be verified.',
                ),
            ]),
            $this->centralResponse(),
        ]);

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(1, $run->refusedByPeer);
        $this->assertTrue($run->needsAttention());

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->status);
        $this->assertSame(1, $operation->retry_count);
        $this->assertStringContainsString('refused', (string) $operation->failure_reason);

        // A second run has nothing left to offer.
        Http::fake([
            'central.example.org/api/node-sync' => Http::response($this->centralResponse()),
        ]);

        $this->assertSame(0, app(NodeSyncClient::class)->sync()->pushed);
    }

    public function test_an_operation_this_node_refuses_is_reported_back_to_central(): void
    {
        $this->asOnsiteInstall();
        $this->registerApplier();

        $unknownActor = $this->peerEnvelope(['actor_user_id' => (string) Str::uuid()]);

        $this->fakeCentral([
            $this->centralResponse(operations: [$unknownActor]),
            $this->centralResponse(),
        ]);

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(1, $run->refusedHere);
        $this->assertSame(0, $run->stored);
        $this->assertDatabaseCount('node_operations', 0);

        $bodies = $this->sentBodies();

        $this->assertSame($unknownActor->uuid(), $bodies[1]['refused'][0]['uuid']);
        $this->assertSame(
            NodeOperationRejectedException::REASON_UNKNOWN_ACTOR_USER,
            $bodies[1]['refused'][0]['reason_code'],
        );
        $this->assertSame([], $bodies[1]['acknowledged']);
    }

    /**
     * An operation that cannot be applied yet is still held here, so it is
     * acknowledged rather than left for central to resend forever.
     */
    public function test_an_operation_that_cannot_be_applied_is_still_acknowledged(): void
    {
        $this->asOnsiteInstall();

        $unapplicable = $this->peerEnvelope(['entity_type' => 'not_understood_yet']);

        $this->fakeCentral([
            $this->centralResponse(operations: [$unapplicable]),
            $this->centralResponse(),
        ]);

        $run = app(NodeSyncClient::class)->sync();

        $this->assertSame(1, $run->stored);
        $this->assertSame(1, $run->unapplied);
        $this->assertTrue($run->needsAttention());

        $this->assertDatabaseHas('node_operations', [
            'uuid' => $unapplicable->uuid(),
            'status' => NodeOperation::STATUS_FAILED,
        ]);

        $this->assertSame([$unapplicable->uuid()], $this->sentBodies()[1]['acknowledged']);
    }

    public function test_a_run_signs_the_exchange_with_this_nodes_key(): void
    {
        $this->asOnsiteInstall();

        $this->fakeCentral([$this->centralResponse()]);

        app(NodeSyncClient::class)->sync();

        $body = $this->sentBodies()[0];

        $this->assertSame((string) $this->localNode->getKey(), $body['source_node_id']);
        $this->assertTrue($this->algorithm->verify(
            NodeSyncRequest::fromArray($body)->canonicalPayload(),
            $body['signature'],
            (string) $this->localNode->public_key,
        ));
    }

    public function test_an_unpaired_node_does_not_sync(): void
    {
        $this->asOnsiteInstall();

        // Changing the central node URL puts pairing back into recheck
        // (technical spec 7.3), and event data should not be pushed to whatever
        // now answers there until pairing is confirmed again.
        app(NodePairingState::class)->storeOverride(
            $this->localNode,
            NodePairingState::CONFIG_CENTRAL_NODE_URL,
            'https://other-central.example.org',
        );

        Http::fake();

        try {
            app(NodeSyncClient::class)->sync();
            $this->fail('A node whose pairing needs a recheck should not sync.');
        } catch (NodeSyncException $failure) {
            $this->assertSame(NodeSyncException::REASON_NOT_PAIRED, $failure->reason);
        }

        Http::assertNothingSent();
    }

    public function test_a_central_node_does_not_initiate_sync(): void
    {
        $this->asCentralInstall();

        Http::fake();

        try {
            app(NodeSyncClient::class)->sync();
            $this->fail('A central node should not initiate sync.');
        } catch (NodeSyncException $failure) {
            $this->assertSame(NodeSyncException::REASON_ROLE_CANNOT_SYNC, $failure->reason);
        }

        Http::assertNothingSent();
    }

    public function test_event_mode_refuses_syncing_over_plain_http(): void
    {
        $this->asOnsiteInstall(centralUrl: 'http://central.example.org');

        config(['meridian.event_mode.enabled' => true]);

        Http::fake();

        try {
            app(NodeSyncClient::class)->sync();
            $this->fail('Event mode should refuse node sync over plain HTTP.');
        } catch (NodeSyncException $failure) {
            $this->assertSame(NodeSyncException::REASON_INSECURE_CENTRAL_URL, $failure->reason);
        }

        Http::assertNothingSent();
    }

    // The scheduled loop

    public function test_the_sync_command_reports_a_run(): void
    {
        $this->asOnsiteInstall();
        $this->registerApplier();

        $this->fakeCentral([
            $this->centralResponse(operations: [$this->peerEnvelope()]),
            $this->centralResponse(),
        ]);

        $this->artisan('meridian:node-sync')
            ->expectsOutputToContain('received 1 (1 applied')
            ->assertSuccessful();
    }

    /**
     * A normal internet outage on an on-site node is the designed behavior, not
     * a scheduler failure to page someone about.
     */
    public function test_the_sync_command_survives_an_unreachable_central(): void
    {
        $this->asOnsiteInstall();
        $this->queueLocalOperation();

        Http::fake(function (): never {
            throw new ConnectionException('Connection timed out.');
        });

        $this->artisan('meridian:node-sync')
            ->expectsOutputToContain('will be sent on a later run')
            ->assertSuccessful();

        $this->assertDatabaseHas('node_operations', ['status' => NodeOperation::STATUS_PENDING]);
    }

    /**
     * A node that is not an on-site node never syncs, so a minutely scheduled
     * command must not report that as a defect.
     */
    public function test_the_sync_command_does_not_fail_on_a_node_that_never_syncs(): void
    {
        $this->asCentralInstall();

        Http::fake();

        $this->artisan('meridian:node-sync')
            ->expectsOutputToContain('does not sync with central')
            ->assertSuccessful();
    }

    public function test_the_sync_command_fails_when_the_node_needs_a_human(): void
    {
        $this->asOnsiteInstall(centralUrl: 'http://central.example.org');

        config(['meridian.event_mode.enabled' => true]);

        Http::fake();

        $this->artisan('meridian:node-sync')
            ->expectsOutputToContain('Event mode requires HTTPS')
            ->assertFailed();
    }

    // God mode sync panel

    public function test_the_node_config_screen_reports_sync_state_from_the_operation_log(): void
    {
        $this->asOnsiteInstall();
        $this->registerApplier();

        $queued = $this->queueLocalOperation();
        $delivered = $this->queueLocalOperation();

        $this->fakeCentral([
            $this->centralResponse(
                results: [NodeSyncOperationResult::stored($delivered->uuid)],
                operations: [$this->peerEnvelope()],
            ),
            $this->centralResponse(),
        ]);

        app(NodeSyncClient::class)->sync();

        $health = app(NodeSyncHealth::class)->describe($this->localNode->fresh());

        $this->assertSame(NodeSyncHealth::STATUS_QUEUED, $health['status']);
        $this->assertSame(1, $health['queued']);
        $this->assertSame(1, $health['delivered']);
        $this->assertSame(1, $health['applied']);
        $this->assertSame(0, $health['undelivered']);
        $this->assertNotNull($health['last_sent_at']);
        $this->assertNotNull($health['last_received_at']);

        $this->actingAs($this->godModeUser())
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Node sync')
            ->assertSee('Operations queued for the peer')
            ->assertSee('Queued to send');

        $this->assertNotNull($queued->fresh());
    }

    /**
     * A backlog is what an on-site node is supposed to build during an outage,
     * so it must not read as a fault.
     */
    public function test_queued_work_alone_does_not_ask_for_attention(): void
    {
        $this->asOnsiteInstall();

        $this->queueLocalOperation();

        $health = app(NodeSyncHealth::class)->describe($this->localNode);

        $this->assertSame(NodeSyncHealth::STATUS_QUEUED, $health['status']);
        $this->assertSame(1, $health['queued']);
        $this->assertSame([], $health['failures']);
    }

    public function test_a_failed_operation_puts_the_panel_into_attention(): void
    {
        $this->asOnsiteInstall();

        // No applier is registered, so the pulled operation is stored and
        // marked failed: reachable peer, local problem.
        $this->fakeCentral([
            $this->centralResponse(operations: [$this->peerEnvelope()]),
            $this->centralResponse(),
        ]);

        app(NodeSyncClient::class)->sync();

        $health = app(NodeSyncHealth::class)->describe($this->localNode);

        $this->assertSame(NodeSyncHealth::STATUS_ATTENTION, $health['status']);
        $this->assertSame(1, $health['unapplied']);
        $this->assertCount(1, $health['failures']);
        $this->assertSame('inbound', $health['failures'][0]['direction']);

        $this->actingAs($this->godModeUser())
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Recent operation failures')
            ->assertSee('could not be applied');
    }

    public function test_a_refused_exchange_is_shown_even_though_nothing_was_stored(): void
    {
        $this->asCentralInstall();

        $this->peerNode->forceFill(['revoked_at' => now()])->save();
        $this->postJson(route('api.node-sync.store'), $this->signedRequest())->assertStatus(401);

        $health = app(NodeSyncHealth::class)->describe($this->localNode);

        $this->assertSame(NodeSyncHealth::STATUS_ATTENTION, $health['status']);
        $this->assertSame(
            NodeSyncException::REASON_SOURCE_NODE_NOT_ACCEPTED,
            $health['refusals'][0]['reason_code'],
        );
        $this->assertDatabaseCount('node_operations', 0);

        $this->actingAs($this->godModeUser())
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Recent refused exchanges')
            ->assertSee(NodeSyncException::REASON_SOURCE_NODE_NOT_ACCEPTED);
    }

    public function test_a_node_that_has_never_synced_reads_as_idle(): void
    {
        $this->asOnsiteInstall();

        $health = app(NodeSyncHealth::class)->describe($this->localNode);

        $this->assertSame(NodeSyncHealth::STATUS_IDLE, $health['status']);

        $this->actingAs($this->godModeUser())
            ->get(route('platform.node.config'))
            ->assertOk()
            ->assertSee('Nothing synced yet');
    }

    // Installs

    /**
     * This install is central: it holds its own signing keys and knows the
     * on-site node as a paired peer.
     */
    private function asCentralInstall(): void
    {
        $this->peerKeys = app(NodeKeyPairGenerator::class)->generate();

        $this->localNode = Node::factory()->central()->signing()->create([
            'node_name' => 'juplaya.central',
        ]);

        $this->peerNode = Node::factory()->onsite()->remote()->create([
            'node_name' => 'juplaya.2027.onsite',
            'public_key' => $this->peerKeys['public_key'],
        ]);
    }

    /**
     * This install is on-site: it holds its own signing keys, knows central as
     * a paired peer, and has completed pairing against a central URL.
     */
    private function asOnsiteInstall(string $centralUrl = 'https://central.example.org'): void
    {
        $this->peerKeys = app(NodeKeyPairGenerator::class)->generate();

        $this->localNode = Node::factory()->onsite()->signing()->create([
            'node_name' => 'juplaya.2027.onsite',
        ]);

        $this->peerNode = Node::factory()->central()->remote()->create([
            'node_name' => 'juplaya.central',
            'public_key' => $this->peerKeys['public_key'],
        ]);

        app(NodePairingState::class)->recordPairing(
            node: $this->localNode,
            centralNodeUrl: $centralUrl,
            centralNode: $this->peerNode,
            pairedAt: now(),
        );
    }

    // Exchange helpers

    /**
     * @param  list<NodeOperationEnvelope>  $operations
     * @param  list<string>  $acknowledged
     * @param  list<NodeSyncOperationResult>  $refused
     */
    private function exchange(
        array $operations = [],
        array $acknowledged = [],
        array $refused = [],
    ): TestResponse {
        return $this->postJson(
            route('api.node-sync.store'),
            $this->signedRequest($operations, $acknowledged, $refused),
        );
    }

    /**
     * An exchange signed the way a real peer signs it: over the canonical
     * payload, with that node's own private key.
     *
     * @param  list<NodeOperationEnvelope>  $operations
     * @param  list<string>  $acknowledged
     * @param  list<NodeSyncOperationResult>  $refused
     * @param  array{public_key: string, private_key: string}|null  $keys
     * @return array<string, mixed>
     */
    private function signedRequest(
        array $operations = [],
        array $acknowledged = [],
        array $refused = [],
        ?CarbonImmutable $sentAt = null,
        ?array $keys = null,
    ): array {
        $keys ??= $this->peerKeys;

        $request = NodeSyncRequest::create(
            sourceNode: $this->peerNode,
            operations: $operations,
            acknowledged: $acknowledged,
            refused: $refused,
            sentAt: $sentAt,
        );

        return $request
            ->signedWith($this->algorithm->sign($request->canonicalPayload(), $keys['private_key']))
            ->toArray();
    }

    /**
     * An operation created and queued on this install, through the same path a
     * workflow would use.
     */
    private function queueLocalOperation(
        string $operationType = 'upsert',
        string $entityType = 'field_report',
        ?Node $targetNode = null,
    ): NodeOperation {
        return app(NodeOperationRecorder::class)->record(
            operationType: $operationType,
            entityType: $entityType,
            entityId: (string) Str::uuid(),
            actorUser: $this->actor,
            targetNode: $targetNode,
            originNode: $this->localNode,
        );
    }

    /**
     * An operation the peer node created and signed on its own side.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function peerEnvelope(array $overrides = []): NodeOperationEnvelope
    {
        $operation = new NodeOperation;

        $operation->forceFill([
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => (string) $this->peerNode->getKey(),
            'target_node_id' => null,
            'actor_user_id' => (string) $this->actor->getKey(),
            'actor_device_id' => null,
            'operation_type' => 'upsert',
            'entity_type' => 'field_report',
            'entity_id' => (string) Str::uuid(),
            'event_id' => null,
            'created_at' => now(),
            'payload_json' => null,
            ...$overrides,
        ]);

        $operation->forceFill([
            'hash' => $this->signer->hashFor($operation),
            'signature' => $this->algorithm->sign(
                $this->signer->canonicalPayload($operation),
                $this->peerKeys['private_key'],
            ),
        ]);

        return NodeOperationEnvelope::fromOperation($operation);
    }

    /**
     * What central answers with.
     *
     * @param  list<NodeSyncOperationResult>  $results
     * @param  list<NodeOperationEnvelope>  $operations
     * @return array<string, mixed>
     */
    private function centralResponse(array $results = [], array $operations = []): array
    {
        return [
            'node_id' => (string) $this->peerNode->getKey(),
            'received_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'results' => array_map(
                static fn (NodeSyncOperationResult $result): array => $result->toArray(),
                $results,
            ),
            'operations' => array_map(
                static fn (NodeOperationEnvelope $envelope): array => $envelope->toArray(),
                $operations,
            ),
            'acknowledged' => 0,
            'refusals_recorded' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|callable  $responses  one per exchange, or a callback over the request body
     */
    private function fakeCentral(array|callable $responses): void
    {
        if (is_callable($responses)) {
            Http::fake([
                'central.example.org/api/node-sync' => function ($request) use ($responses) {
                    return Http::response($responses($request->data()));
                },
            ]);

            return;
        }

        Http::fake([
            'central.example.org/api/node-sync' => Http::sequence()
                ->pushResponse(...array_map(
                    static fn (array $body) => Http::response($body),
                    $responses,
                ))
                ->whenEmpty(Http::response($responses[array_key_last($responses)] ?? [])),
        ]);
    }

    /**
     * The bodies this node actually sent, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function sentBodies(): array
    {
        $bodies = [];

        Http::recorded(function ($request) use (&$bodies): bool {
            $bodies[] = $request->data();

            return true;
        });

        return $bodies;
    }

    private function assertRefusalAudited(string $sourceNodeId, string $reasonCode): void
    {
        $audit = AuditEvent::query()
            ->where('action', NodeSyncService::AUDIT_REFUSED)
            ->where('entity_id', $sourceNodeId)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit, 'The refused exchange was not audited.');
        $this->assertSame(AuditEvent::SOURCE_SYNC, $audit->source_context);
        $this->assertSame($reasonCode, $audit->after_json['reason_code']);
    }

    private function godModeUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.node.config' => true,
            ],
        ]);
    }

    private function registerApplier(): SyncLoopRecordingApplier
    {
        $applier = new SyncLoopRecordingApplier;

        $this->appliers->register($applier);

        return $applier;
    }
}

/**
 * A test applier that counts what it applied. Registered per test, because the
 * registry is what each entity's owning task registers into.
 */
class SyncLoopRecordingApplier implements NodeOperationApplier
{
    public int $applied = 0;

    /** @var list<SignedNodeOperation> */
    public array $seen = [];

    public ?string $failWith = null;

    public function supports(SignedNodeOperation $operation): bool
    {
        return in_array($operation->entityType, ['field_report', 'incident', 'policy_document'], true);
    }

    public function apply(SignedNodeOperation $operation): void
    {
        $this->seen[] = $operation;

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->applied++;
    }
}
