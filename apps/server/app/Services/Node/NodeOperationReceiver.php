<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Receives, stores, and applies remote node operations (technical spec 10.1,
 * 10.4; data/API 13.3).
 *
 * Technical spec 10.1 states the two rules this service exists to implement.
 * The receiver stores remote operations before applying them, and operations
 * are idempotent, so receiving the same operation multiple times is safe.
 *
 * Store-before-apply is a durability rule, not an ordering preference, so
 * {@see receive()} and {@see apply()} are separate calls with separate
 * transactions. An operation is committed as `received` first; only then does
 * application run. If application throws, if the process dies, or if the node
 * loses power mid-apply, the operation is still in the log and can be applied
 * later. Combining the two into one transaction would undo exactly the
 * guarantee the rule asks for.
 *
 * Idempotency rests on `uuid`, the idempotency key (data/API 13.3). A
 * redelivery resolves to the stored operation, is not written twice, and is not
 * applied twice; the receipt reports it as a duplicate so a sync run can still
 * be described honestly. The same key arriving with different content is a
 * replay rather than a redelivery and is refused, because a stored operation is
 * append-only and the stored copy is the one both nodes already agreed on.
 *
 * Refusal and failure are different outcomes. An operation that is malformed,
 * unauthenticated, addressed to another node, or sent by a revoked node or
 * device is refused and never enters the log; refusals are audited, because a
 * refused operation leaves no row behind to record itself. An operation that is
 * accepted but cannot be applied is stored, marked `failed` with a
 * `failure_reason` and an incremented `retry_count`, and stays recoverable —
 * failed sync actions remain recoverable (technical spec 9.2), and an
 * unapplied operation must not block unrelated sync (technical spec 10.3), so
 * application failures do not propagate out of {@see apply()}.
 *
 * Nothing is applied from `payload_json`. Signatures cover the normalized
 * operation fields only (technical spec 10.4), so the payload is
 * unauthenticated and can be changed in transit without breaking verification.
 * The payload is stored because the schema carries it and conflict review will
 * want to show it, but appliers receive {@see SignedNodeOperation} and never
 * see it, so local state is only ever written from signed content.
 *
 * Event authority is checked here too, once the operation is known to be
 * authentic. During an active event window the on-site primary node is
 * authoritative for event-scoped records and edits not from that node are
 * refused (technical spec 10.2), so an event-scoped operation from any other
 * node is refused rather than stored. Applying, by contrast, stands the local
 * write guard down: an operation that reached application has already been
 * accepted from the authoritative node, and data arriving from that node is
 * precisely what a read-only node still accepts.
 *
 * Scope. This is the receiving half of the path. Sending and the loop that
 * drives both halves belong to the bidirectional sync loop (M12.5), and
 * disagreements between local and remote state belong to the sync conflict
 * queue (M12.8, M12.9). The governance freeze (M12.7) is not applied to arriving
 * operations either: no node can create a document or fragment operation while
 * the window is open, so one that arrives mid-window carries a pre-window edit,
 * and deciding otherwise would mean trusting the sender's clock. What this
 * service enforces is narrower: the operation
 * is well formed, it is addressed here, it comes from a node and device this
 * install still accepts, its node signature verifies, its origin holds
 * authority over any event it names, and it is stored before anything is
 * applied.
 */
class NodeOperationReceiver
{
    public const AUDIT_REJECTED = 'node_operation.rejected';

    public function __construct(
        private readonly NodeOperationSigner $signer,
        private readonly NodeOperationApplierRegistry $appliers,
        private readonly NodeSetupService $nodes,
        private readonly AuditService $audit,
        private readonly EventAuthority $authority,
        private readonly EventScopedWriteGuard $writeGuard,
        private readonly GovernanceWriteGuard $governanceGuard,
    ) {}

    /**
     * Store a remote operation without applying it.
     *
     * @param  array<array-key, mixed>|NodeOperationEnvelope  $envelope
     *
     * @throws NodeOperationRejectedException
     */
    public function receive(
        array|NodeOperationEnvelope $envelope,
        ?Node $receivingNode = null,
    ): NodeOperationReceipt {
        $envelope = $envelope instanceof NodeOperationEnvelope
            ? $envelope
            : NodeOperationEnvelope::fromArray($envelope);

        // Redelivery is resolved before anything else. An operation this node
        // already holds is settled: it was accepted once, it may already be
        // applied, and re-deciding it against today's node and device state
        // would make a safe retry fail for reasons that postdate the original
        // acceptance.
        $stored = $this->storedOperation($envelope);

        if ($stored instanceof NodeOperation) {
            return new NodeOperationReceipt($stored, stored: false);
        }

        $node = $receivingNode ?? $this->nodes->activeNode();

        if (! $node instanceof Node) {
            throw $this->refuse($envelope, NodeOperationRejectedException::nodeNotConfigured());
        }

        $originNode = $this->acceptableOriginNode($envelope, $node);

        $this->assertAddressedToThisNode($envelope, $node, $originNode);
        $this->assertKnownActor($envelope, $originNode);

        $operation = $envelope->toOperation();

        if (! $this->signer->verify($operation, $originNode)) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::signatureRejected(),
                $originNode,
            );
        }

        $this->assertEventAuthority($envelope, $originNode);

        return $this->store($envelope, $operation);
    }

    /**
     * Apply a stored operation to local state.
     *
     * Applying an operation that is already applied is a no-op, which is what
     * makes a redelivered or retried operation safe. An application that cannot
     * complete marks the operation `failed` and returns it rather than raising,
     * so one unapplicable operation does not stop a sync run.
     */
    public function apply(NodeOperation $operation): NodeOperation
    {
        if (! $operation->exists) {
            throw new RuntimeException(
                'Node operations are stored before they are applied; this operation is not recorded yet.',
            );
        }

        if ($operation->isApplied()) {
            return $operation;
        }

        // Appliers are handed the signed projection rather than the row, so
        // local state can only be written from fields the origin node signed.
        // `payload_json` is outside the signed message (data/API 13.3) and is
        // stored for conflict display and diagnosis, not applied.
        $signed = SignedNodeOperation::fromOperation($operation);

        $applier = $this->appliers->applierFor($signed);

        if (! $applier instanceof NodeOperationApplier) {
            return $this->markFailed($operation, sprintf(
                'No applier is registered for %s operations on entity type "%s".',
                $signed->operationType,
                $signed->entityType,
            ));
        }

        try {
            // The entity write and the `applied` mark commit together, so there
            // is no window in which local state changed but the operation still
            // looks unapplied.
            //
            // Event authority was decided on receipt, and data arriving from the
            // authoritative node is the documented exception to a read-only node
            // (technical spec 10.2), so the local write guard stands down here.
            // Applying is the one write path that has already answered the
            // question the guard asks.
            //
            // The governance freeze stands down for a different reason. No node
            // can create a document or fragment operation during the window, so
            // one arriving mid-window carries an edit made before it opened;
            // refusing it would discard content central prepared for the event.
            $this->writeGuard->withoutEnforcement(fn () => $this->governanceGuard->withoutEnforcement(
                fn () => DB::transaction(function () use ($applier, $signed, $operation): void {
                    $applier->apply($signed);

                    $operation->forceFill([
                        'status' => NodeOperation::STATUS_APPLIED,
                        'applied_at' => now(),
                        'failure_reason' => null,
                    ])->save();
                }),
            ));
        } catch (Throwable $failure) {
            // The rolled-back transaction leaves the in-memory model holding
            // attributes the database never kept, so the row is re-read before
            // the failure is recorded.
            $operation->refresh();

            return $this->markFailed($operation, $failure->getMessage());
        }

        return $operation;
    }

    /**
     * Store a remote operation and then apply it, in that order.
     *
     * @param  array<array-key, mixed>|NodeOperationEnvelope  $envelope
     *
     * @throws NodeOperationRejectedException
     */
    public function receiveAndApply(
        array|NodeOperationEnvelope $envelope,
        ?Node $receivingNode = null,
    ): NodeOperationReceipt {
        $receipt = $this->receive($envelope, $receivingNode);

        $this->apply($receipt->operation);

        return $receipt;
    }

    /**
     * @throws NodeOperationRejectedException
     */
    private function store(NodeOperationEnvelope $envelope, NodeOperation $operation): NodeOperationReceipt
    {
        $operation->forceFill([
            'status' => NodeOperation::STATUS_RECEIVED,
            'received_at' => now(),
            // `sent_at` describes the sending node's own delivery attempt and
            // is not part of what travels, so this node leaves it unset rather
            // than inventing a time it did not observe.
            'sent_at' => null,
            'applied_at' => null,
            'failure_reason' => null,
            'retry_count' => 0,
        ]);

        try {
            $operation->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery of the same operation won the unique index
            // on `uuid`. That is the idempotency key doing its job, so the
            // winner is resolved and returned instead of duplicated.
            $stored = $this->storedOperation($envelope);

            if (! $stored instanceof NodeOperation) {
                throw $this->refuse(
                    $envelope,
                    NodeOperationRejectedException::uuidConflict($envelope->uuid()),
                );
            }

            return new NodeOperationReceipt($stored, stored: false);
        }

        return new NodeOperationReceipt($operation, stored: true);
    }

    /**
     * The operation already held under this envelope's idempotency key, or null
     * when the operation is new.
     *
     * @throws NodeOperationRejectedException when the key is held by different content
     */
    private function storedOperation(NodeOperationEnvelope $envelope): ?NodeOperation
    {
        $stored = NodeOperation::query()->where('uuid', $envelope->uuid())->first();

        if ($stored === null) {
            return null;
        }

        if (! $envelope->matchesStored($stored)) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::uuidConflict($envelope->uuid()),
                $stored->originNode()->first(),
            );
        }

        return $stored;
    }

    /**
     * @throws NodeOperationRejectedException
     */
    private function acceptableOriginNode(NodeOperationEnvelope $envelope, Node $receivingNode): Node
    {
        $originNode = Node::query()->find($envelope->originNodeId());

        if (! $originNode instanceof Node) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::unknownOriginNode($envelope->originNodeId()),
                $receivingNode,
            );
        }

        if ($originNode->isRevoked()) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::originNodeRevoked((string) $originNode->node_name),
                $originNode,
            );
        }

        return $originNode;
    }

    /**
     * @throws NodeOperationRejectedException
     */
    private function assertAddressedToThisNode(
        NodeOperationEnvelope $envelope,
        Node $receivingNode,
        Node $originNode,
    ): void {
        $targetNodeId = $envelope->targetNodeId();

        // An unaddressed operation is broadcast to whichever peer receives it;
        // an addressed one names the node that may apply it.
        if ($targetNodeId !== null && $targetNodeId !== (string) $receivingNode->getKey()) {
            throw $this->refuse($envelope, NodeOperationRejectedException::wrongTargetNode(), $originNode);
        }
    }

    /**
     * An event-scoped operation may only come from the node that holds
     * authority for that event (technical spec 10.2).
     *
     * This runs after the signature check, so an operation is refused for
     * authority only once it is known to be authentic; a forged operation is
     * refused as a forgery rather than reported as an authority problem.
     *
     * An operation naming an event this install does not hold is not refused
     * here. There is no window to evaluate and no authority to compare against,
     * and the operation is authentic; it is stored and left to fail on
     * application, where it stays recoverable once the event arrives.
     *
     * @throws NodeOperationRejectedException
     */
    private function assertEventAuthority(NodeOperationEnvelope $envelope, Node $originNode): void
    {
        $eventId = $envelope->eventId();

        if ($eventId === null) {
            return;
        }

        $event = Event::query()->find($eventId);

        if (! $event instanceof Event) {
            return;
        }

        if ($this->authority->acceptsOperationFrom($originNode, $event)) {
            return;
        }

        $authoritativeNode = $this->authority->authoritativeNodeFor($event);

        throw $this->refuse(
            $envelope,
            NodeOperationRejectedException::eventAuthorityRefused(
                (string) $event->name,
                (string) $authoritativeNode?->node_name,
            ),
            $originNode,
        );
    }

    /**
     * The acting user and device travel as identifiers, so this node has to
     * hold both before it can attribute anything to them. A revoked device is
     * refused here rather than at signing time, because revocation is the
     * receiving node's decision about who it still accepts work from
     * (technical spec 12.1, 12.2).
     *
     * @throws NodeOperationRejectedException
     */
    private function assertKnownActor(NodeOperationEnvelope $envelope, Node $originNode): void
    {
        if (! User::query()->whereKey($envelope->actorUserId())->exists()) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::unknownActorUser($envelope->actorUserId()),
                $originNode,
            );
        }

        $deviceId = $envelope->actorDeviceId();

        if ($deviceId === null) {
            return;
        }

        $device = Device::query()->find($deviceId);

        if (! $device instanceof Device) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::unknownActorDevice($deviceId),
                $originNode,
            );
        }

        if ($device->isRevoked()) {
            throw $this->refuse(
                $envelope,
                NodeOperationRejectedException::actorDeviceRevoked($deviceId),
                $originNode,
            );
        }
    }

    /**
     * Record a refusal and hand back the exception for the caller to throw.
     *
     * A refused operation is never stored, so `node_operations` cannot be the
     * record of it. Without this audit event a peer could send unauthenticated
     * or revoked-node traffic and leave no trace anywhere (technical spec 23;
     * data/API 14.1).
     *
     * The refused operation's own scope is written into `after_json` rather
     * than into the audit event's scope columns, because a refused operation
     * may name an event, node, or device this install does not have, and the
     * record of a refusal must not itself depend on trusting what was refused.
     */
    private function refuse(
        NodeOperationEnvelope $envelope,
        NodeOperationRejectedException $rejection,
        ?Node $actorNode = null,
    ): NodeOperationRejectedException {
        $this->audit->record(
            action: self::AUDIT_REJECTED,
            entityType: (new NodeOperation)->getMorphClass(),
            entityId: $envelope->uuid(),
            actorNode: $actorNode,
            reason: $rejection->getMessage(),
            sourceContext: AuditEvent::SOURCE_SYNC,
            after: [
                'reason_code' => $rejection->reason,
                ...$envelope->normalized,
            ],
        );

        return $rejection;
    }

    private function markFailed(NodeOperation $operation, string $reason): NodeOperation
    {
        $operation->forceFill([
            'status' => NodeOperation::STATUS_FAILED,
            'failure_reason' => $reason,
            'retry_count' => (int) $operation->retry_count + 1,
        ])->save();

        return $operation;
    }
}
