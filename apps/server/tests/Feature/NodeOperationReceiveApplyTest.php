<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeOperationApplier;
use App\Services\Node\NodeOperationApplierRegistry;
use App\Services\Node\NodeOperationEnvelope;
use App\Services\Node\NodeOperationReceiver;
use App\Services\Node\NodeOperationRejectedException;
use App\Services\Node\NodeOperationSigner;
use App\Services\Node\NodeSignatureAlgorithm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Receiving, storing, and applying remote node operations (technical spec 10.1,
 * 10.4; data/API 13.3).
 *
 * The peer node is simulated the way a real one behaves: it holds its own
 * keypair, signs the canonical payload on its own side, and this install knows
 * it only as a peer `nodes` record carrying a public key.
 */
class NodeOperationReceiveApplyTest extends TestCase
{
    use RefreshDatabase;

    private NodeOperationReceiver $receiver;

    private NodeOperationSigner $signer;

    private NodeOperationApplierRegistry $appliers;

    private Node $receivingNode;

    private Node $originNode;

    private User $actor;

    /** @var array{public_key: string, private_key: string} */
    private array $originKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiver = app(NodeOperationReceiver::class);
        $this->signer = app(NodeOperationSigner::class);
        $this->appliers = app(NodeOperationApplierRegistry::class);

        $this->receivingNode = Node::factory()->central()->signing()->create();

        $this->originKeys = app(NodeKeyPairGenerator::class)->generate();
        $this->originNode = Node::factory()->onsite()->remote()->create([
            'public_key' => $this->originKeys['public_key'],
        ]);

        $this->actor = User::factory()->create();
    }

    public function test_receiving_stores_the_operation_before_applying_it(): void
    {
        $applier = $this->registerApplier();

        $receipt = $this->receiver->receive($this->signedEnvelope());

        $this->assertTrue($receipt->stored);
        $this->assertFalse($receipt->isDuplicate());
        $this->assertDatabaseCount('node_operations', 1);

        $operation = $receipt->operation->fresh();

        $this->assertSame(NodeOperation::STATUS_RECEIVED, $operation->status);
        $this->assertNotNull($operation->received_at);
        $this->assertNull($operation->applied_at);
        $this->assertFalse($operation->isApplied());
        $this->assertSame(0, $operation->retry_count);
        $this->assertSame(0, $applier->applied);
    }

    public function test_a_received_operation_keeps_the_origin_content_and_verifies(): void
    {
        $envelope = $this->signedEnvelope(['payload_json' => ['status' => 'closed']]);

        $operation = $this->receiver->receive($envelope)->operation->fresh();

        $this->assertSame($envelope->uuid(), $operation->uuid);
        $this->assertSame($envelope->signature, $operation->signature);
        $this->assertSame($envelope->hash, $operation->hash);
        $this->assertSame(['status' => 'closed'], $operation->payload_json);
        $this->assertSame(
            $envelope->normalized['created_at'],
            $operation->created_at->utc()->toIso8601String(),
        );
        $this->assertTrue($this->signer->verify($operation));
    }

    /**
     * The receiver did not send the operation, so it records only the delivery
     * state it observed (data/API 13.3).
     */
    public function test_a_received_operation_does_not_claim_a_sent_time(): void
    {
        $operation = $this->receiver->receive($this->signedEnvelope())->operation->fresh();

        $this->assertNull($operation->sent_at);
    }

    public function test_receiving_the_same_operation_twice_stores_one_row(): void
    {
        $envelope = $this->signedEnvelope();

        $first = $this->receiver->receive($envelope);
        $second = $this->receiver->receive($envelope->toArray());

        $this->assertTrue($first->stored);
        $this->assertFalse($second->stored);
        $this->assertTrue($second->isDuplicate());
        $this->assertSame($first->operation->getKey(), $second->operation->getKey());
        $this->assertDatabaseCount('node_operations', 1);
    }

    public function test_applying_the_same_operation_twice_applies_it_once(): void
    {
        $applier = $this->registerApplier();
        $envelope = $this->signedEnvelope();

        $first = $this->receiver->receiveAndApply($envelope);
        $appliedAt = $first->operation->fresh()->applied_at;

        $second = $this->receiver->receiveAndApply($envelope->toArray());

        $this->assertSame(1, $applier->applied);
        $this->assertTrue($second->isDuplicate());
        $this->assertTrue($second->wasApplied());
        $this->assertDatabaseCount('node_operations', 1);
        $this->assertTrue($appliedAt->equalTo($second->operation->fresh()->applied_at));
    }

    public function test_applying_an_already_applied_operation_is_a_no_op(): void
    {
        $applier = $this->registerApplier();
        $operation = $this->receiver->receiveAndApply($this->signedEnvelope())->operation;

        $this->receiver->apply($operation);
        $this->receiver->apply($operation->fresh());

        $this->assertSame(1, $applier->applied);
        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->fresh()->status);
    }

    public function test_applying_marks_the_operation_applied(): void
    {
        $this->registerApplier();

        $receipt = $this->receiver->receiveAndApply($this->signedEnvelope());
        $operation = $receipt->operation->fresh();

        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->status);
        $this->assertNotNull($operation->applied_at);
        $this->assertNull($operation->failure_reason);
        $this->assertTrue($receipt->wasApplied());
        $this->assertFalse($receipt->hasFailed());
    }

    public function test_a_replayed_idempotency_key_with_different_content_is_refused(): void
    {
        $envelope = $this->signedEnvelope();
        $this->receiver->receive($envelope);

        $replay = $this->signedEnvelope([
            'uuid' => $envelope->uuid(),
            'entity_id' => (string) Str::uuid(),
        ]);

        $rejection = $this->assertRefused($replay);

        $this->assertSame(NodeOperationRejectedException::REASON_UUID_CONFLICT, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 1);
        $this->assertDatabaseHas('node_operations', ['hash' => $envelope->hash]);
        $this->assertRefusalAudited($envelope->uuid(), NodeOperationRejectedException::REASON_UUID_CONFLICT);
    }

    public function test_an_unverifiable_signature_is_refused_and_nothing_is_stored(): void
    {
        $envelope = $this->signedEnvelope();

        $tampered = $envelope->toArray();
        $tampered['signature'] = base64_encode(random_bytes(64));

        $rejection = $this->assertRefused($tampered);

        $this->assertSame(NodeOperationRejectedException::REASON_SIGNATURE_REJECTED, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
        $this->assertRefusalAudited($envelope->uuid(), NodeOperationRejectedException::REASON_SIGNATURE_REJECTED);
    }

    public function test_a_rewritten_normalized_field_is_refused(): void
    {
        $rewritten = $this->signedEnvelope()->toArray();
        $rewritten['entity_id'] = (string) Str::uuid();

        $rejection = $this->assertRefused($rewritten);

        $this->assertSame(NodeOperationRejectedException::REASON_SIGNATURE_REJECTED, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_signed_by_another_node_is_refused(): void
    {
        $otherKeys = app(NodeKeyPairGenerator::class)->generate();

        $rejection = $this->assertRefused($this->signedEnvelope(keys: $otherKeys));

        $this->assertSame(NodeOperationRejectedException::REASON_SIGNATURE_REJECTED, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_from_an_unknown_origin_node_is_refused(): void
    {
        $envelope = $this->signedEnvelope(['origin_node_id' => (string) Str::uuid()]);

        $rejection = $this->assertRefused($envelope);

        $this->assertSame(NodeOperationRejectedException::REASON_UNKNOWN_ORIGIN_NODE, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_from_a_revoked_origin_node_is_refused(): void
    {
        $envelope = $this->signedEnvelope();
        $this->originNode->forceFill(['revoked_at' => now()])->save();

        $rejection = $this->assertRefused($envelope);

        $this->assertSame(NodeOperationRejectedException::REASON_ORIGIN_NODE_REVOKED, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
        $this->assertRefusalAudited($envelope->uuid(), NodeOperationRejectedException::REASON_ORIGIN_NODE_REVOKED);
    }

    public function test_an_operation_addressed_to_another_node_is_refused(): void
    {
        $elsewhere = Node::factory()->onsite()->remote()->create();

        $rejection = $this->assertRefused($this->signedEnvelope([
            'target_node_id' => (string) $elsewhere->getKey(),
        ]));

        $this->assertSame(NodeOperationRejectedException::REASON_WRONG_TARGET_NODE, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_addressed_to_this_node_is_stored(): void
    {
        $receipt = $this->receiver->receive($this->signedEnvelope([
            'target_node_id' => (string) $this->receivingNode->getKey(),
        ]));

        $this->assertTrue($receipt->stored);
        $this->assertSame(
            (string) $this->receivingNode->getKey(),
            $receipt->operation->fresh()->target_node_id,
        );
    }

    public function test_an_operation_naming_an_unknown_actor_user_is_refused(): void
    {
        $rejection = $this->assertRefused($this->signedEnvelope([
            'actor_user_id' => (string) Str::uuid(),
        ]));

        $this->assertSame(NodeOperationRejectedException::REASON_UNKNOWN_ACTOR_USER, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_naming_an_unknown_acting_device_is_refused(): void
    {
        $rejection = $this->assertRefused($this->signedEnvelope([
            'actor_device_id' => (string) Str::uuid(),
        ]));

        $this->assertSame(NodeOperationRejectedException::REASON_UNKNOWN_ACTOR_DEVICE, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_from_a_revoked_acting_device_is_refused(): void
    {
        $device = Device::factory()->create(['revoked_at' => now()]);

        $rejection = $this->assertRefused($this->signedEnvelope([
            'actor_device_id' => (string) $device->getKey(),
        ]));

        $this->assertSame(NodeOperationRejectedException::REASON_ACTOR_DEVICE_REVOKED, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_operation_from_an_active_acting_device_is_stored(): void
    {
        $device = Device::factory()->create();

        $receipt = $this->receiver->receive($this->signedEnvelope([
            'actor_device_id' => (string) $device->getKey(),
        ]));

        $this->assertTrue($receipt->stored);
        $this->assertSame((string) $device->getKey(), $receipt->operation->fresh()->actor_device_id);
    }

    public function test_a_malformed_envelope_is_refused_without_storing_or_auditing(): void
    {
        $malformed = $this->signedEnvelope()->toArray();
        unset($malformed['uuid']);

        try {
            $this->receiver->receive($malformed);
            $this->fail('The malformed operation was not refused.');
        } catch (NodeOperationRejectedException $rejection) {
            $this->assertSame(NodeOperationRejectedException::REASON_MALFORMED, $rejection->reason);
        }

        $this->assertDatabaseCount('node_operations', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_a_non_uuid_entity_id_is_refused(): void
    {
        $malformed = $this->signedEnvelope()->toArray();
        $malformed['entity_id'] = 'not-a-uuid';

        $rejection = $this->assertRefused($malformed);

        $this->assertSame(NodeOperationRejectedException::REASON_MALFORMED, $rejection->reason);
    }

    public function test_receiving_is_refused_when_this_install_has_no_node(): void
    {
        $envelope = $this->signedEnvelope();
        $this->receivingNode->forceFill(['revoked_at' => now()])->save();

        $rejection = $this->assertRefused($envelope);

        $this->assertSame(NodeOperationRejectedException::REASON_NODE_NOT_CONFIGURED, $rejection->reason);
        $this->assertDatabaseCount('node_operations', 0);
    }

    public function test_an_application_failure_keeps_the_stored_operation_recoverable(): void
    {
        $applier = $this->registerApplier();
        $applier->failWith = 'The incident is not on this node yet.';

        $receipt = $this->receiver->receiveAndApply($this->signedEnvelope());
        $operation = $receipt->operation->fresh();

        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('The incident is not on this node yet.', $operation->failure_reason);
        $this->assertSame(1, $operation->retry_count);
        $this->assertNull($operation->applied_at);
        $this->assertDatabaseCount('node_operations', 1);

        $applier->failWith = null;

        $retried = $this->receiver->apply($operation);

        $this->assertSame(NodeOperation::STATUS_APPLIED, $retried->status);
        $this->assertNotNull($retried->applied_at);
        $this->assertNull($retried->failure_reason);
        $this->assertSame(1, $retried->retry_count);
    }

    public function test_repeated_application_failures_increment_the_retry_count(): void
    {
        $applier = $this->registerApplier();
        $applier->failWith = 'Still unapplicable.';

        $operation = $this->receiver->receiveAndApply($this->signedEnvelope())->operation;

        $this->receiver->apply($operation);
        $this->receiver->apply($operation);

        $this->assertSame(3, $operation->fresh()->retry_count);
    }

    public function test_an_application_failure_rolls_back_the_partial_entity_write(): void
    {
        $applier = $this->registerApplier();
        $applier->writeDeviceLabel = 'half-applied-device';
        $applier->failWith = 'Failed after writing.';

        $this->receiver->receiveAndApply($this->signedEnvelope());

        $this->assertDatabaseMissing('devices', ['device_label' => 'half-applied-device']);
        $this->assertDatabaseHas('node_operations', ['status' => NodeOperation::STATUS_FAILED]);
    }

    public function test_an_operation_with_no_registered_applier_is_stored_and_marked_failed(): void
    {
        $receipt = $this->receiver->receiveAndApply($this->signedEnvelope(['entity_type' => 'unheard_of']));
        $operation = $receipt->operation->fresh();

        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->status);
        $this->assertStringContainsString('unheard_of', (string) $operation->failure_reason);
        $this->assertNull($operation->applied_at);
        $this->assertDatabaseCount('node_operations', 1);
    }

    public function test_an_unapplied_operation_does_not_block_a_later_one(): void
    {
        $applier = $this->registerApplier();
        $applier->failWith = 'Unapplicable.';

        $blocked = $this->receiver->receiveAndApply($this->signedEnvelope())->operation;

        $applier->failWith = null;

        $later = $this->receiver->receiveAndApply($this->signedEnvelope())->operation;

        $this->assertSame(NodeOperation::STATUS_FAILED, $blocked->fresh()->status);
        $this->assertSame(NodeOperation::STATUS_APPLIED, $later->fresh()->status);
    }

    public function test_applying_an_unstored_operation_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->receiver->apply($this->signedEnvelope()->toOperation());
    }

    public function test_the_envelope_round_trips_through_an_array(): void
    {
        $envelope = $this->signedEnvelope(['payload_json' => ['note' => 'value']]);

        $parsed = NodeOperationEnvelope::fromArray($envelope->toArray());

        $this->assertSame($envelope->toArray(), $parsed->toArray());
        $this->assertSame(
            NodeOperation::NORMALIZED_ATTRIBUTES,
            array_keys($parsed->normalized),
        );
    }

    /**
     * @param  array<string, mixed>|NodeOperationEnvelope  $envelope
     */
    private function assertRefused(array|NodeOperationEnvelope $envelope): NodeOperationRejectedException
    {
        try {
            $this->receiver->receive($envelope);
        } catch (NodeOperationRejectedException $rejection) {
            return $rejection;
        }

        $this->fail('The node operation was not refused.');
    }

    private function assertRefusalAudited(string $uuid, string $reasonCode): void
    {
        $audit = AuditEvent::query()
            ->where('action', NodeOperationReceiver::AUDIT_REJECTED)
            ->where('entity_id', $uuid)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($audit, 'The refusal was not audited.');
        $this->assertSame(AuditEvent::SOURCE_SYNC, $audit->source_context);
        $this->assertSame($reasonCode, $audit->after_json['reason_code']);
        $this->assertSame($uuid, $audit->after_json['uuid']);
    }

    /**
     * An envelope signed the way a remote node signs it: over the canonical
     * payload, with that node's own private key.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array{public_key: string, private_key: string}|null  $keys
     */
    private function signedEnvelope(array $overrides = [], ?array $keys = null): NodeOperationEnvelope
    {
        $keys ??= $this->originKeys;

        $operation = new NodeOperation;
        $operation->forceFill([
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => (string) $this->originNode->getKey(),
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
            'signature' => app(NodeSignatureAlgorithm::class)->sign(
                $this->signer->canonicalPayload($operation),
                $keys['private_key'],
            ),
        ]);

        return NodeOperationEnvelope::fromOperation($operation);
    }

    private function registerApplier(): RecordingNodeOperationApplier
    {
        $applier = new RecordingNodeOperationApplier;

        $this->appliers->register($applier);

        return $applier;
    }
}

/**
 * A test applier that records how many times it ran, can be made to fail, and
 * can write local state before failing so the apply transaction boundary is
 * observable.
 */
class RecordingNodeOperationApplier implements NodeOperationApplier
{
    public int $applied = 0;

    public ?string $failWith = null;

    public ?string $writeDeviceLabel = null;

    public function supports(NodeOperation $operation): bool
    {
        return $operation->entity_type === 'field_report';
    }

    public function apply(NodeOperation $operation): void
    {
        if ($this->writeDeviceLabel !== null) {
            Device::factory()->create(['device_label' => $this->writeDeviceLabel]);
        }

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->applied++;
    }
}
