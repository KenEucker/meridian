<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a node operation on this node and queues it for the peer (technical
 * spec 10.1, 10.2, 10.4; data/API 13.3).
 *
 * This is the sending counterpart to {@see NodeOperationReceiver}. An operation
 * created here is signed with the node private key before it is inserted,
 * because `signature` and `hash` are append-only content that cannot be written
 * afterwards, and it is stored `pending` — the state on-site queues operations
 * in while there is no internet (technical spec 10.2). Nothing about creating an
 * operation depends on the peer being reachable; the sync loop picks up
 * whatever is pending whenever it next runs.
 *
 * What an operation may say is constrained by what a signature covers.
 * Signatures cover the normalized fields only, so an operation's meaning has to
 * live in `operation_type` together with the entity it names; `payload` is
 * stored for conflict review and diagnosis and is never applied (data/API
 * 13.3). Which operations each workflow emits is per-entity behavior owned by
 * the task that owns that entity's sync, exactly as appliers are.
 *
 * Device-originated operations do not come through here. Those are signed by
 * the originating device and countersigned by the accepting node
 * ({@see NodeOperationSigner::countersign()}), which is a different entry point
 * with a different signature story, and they join the same log and the same
 * outbox once countersigned.
 */
class NodeOperationRecorder
{
    public function __construct(
        private readonly NodeOperationSigner $signer,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload  diagnostic only; never applied
     *
     * @throws NodeSigningException
     */
    public function record(
        string $operationType,
        string $entityType,
        string $entityId,
        User $actorUser,
        ?Event $event = null,
        ?Device $actorDevice = null,
        ?Node $targetNode = null,
        ?array $payload = null,
        ?Node $originNode = null,
        string $sourceContext = AuditEvent::SOURCE_SYNC,
    ): NodeOperation {
        $node = $originNode ?? $this->nodes->activeNode();

        if (! $node instanceof Node) {
            throw NodeSigningException::nodeNotConfigured();
        }

        $operation = new NodeOperation;

        $operation->forceFill([
            // The idempotency key is minted here, at the origin, so a
            // redelivery of this operation resolves to the same identity on
            // every node that ever sees it (technical spec 10.1).
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => $node->getKey(),
            'target_node_id' => $targetNode?->getKey(),
            'actor_user_id' => $actorUser->getKey(),
            'actor_device_id' => $actorDevice?->getKey(),
            'operation_type' => $operationType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_id' => $event?->getKey(),
            'payload_json' => $payload,
            'status' => NodeOperation::STATUS_PENDING,
            'sent_at' => null,
            'received_at' => null,
            'applied_at' => null,
            'failure_reason' => null,
            'retry_count' => 0,
        ]);

        $signature = $this->signer->sign($operation, $node);

        DB::transaction(function () use ($operation, $signature, $sourceContext): void {
            $operation->save();

            // Signature metadata is retained in audit data (technical spec
            // 10.4), and the audit event names an operation that exists, so it
            // is written with the insert rather than after it.
            $this->signer->recordSignatures($operation, $signature, $sourceContext);
        });

        return $operation;
    }
}
