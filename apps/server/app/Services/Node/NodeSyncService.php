<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;

/**
 * The answering side of a node-to-node sync exchange (technical spec 10.1,
 * 10.2).
 *
 * One exchange moves operations in both directions. Operations the caller
 * pushed are stored and applied here through the receive path, and this node's
 * own queued operations go back in the response, so central sends to on-site
 * and on-site sends to central without central having to open a connection into
 * the event network.
 *
 * Authentication is at the exchange level and at the operation level, and they
 * answer different questions. Each operation carries its own node signature and
 * is verified by {@see NodeOperationReceiver} before it is stored, which is
 * what makes a pushed operation trustworthy (technical spec 10.4). The exchange
 * signature answers a question the operation signatures cannot: whether the
 * caller is a peer this node is willing to hand operations *to*. Without it any
 * caller could pull this node's queue.
 *
 * One refused operation does not end an exchange. A refusal is recorded against
 * that operation, reported back to the sender, and the rest of the batch is
 * processed, because unresolved problems must not block unrelated sync
 * (technical spec 10.3).
 *
 * Out of scope here. Whether an authentic operation is allowed to change
 * event-scoped state during an active event window is event authority (M12.6,
 * M12.7), and disagreement between local and remote state is recorded by the
 * sync conflict queue (M12.8) with resolution in M12.9. This service decides
 * who may exchange, moves the operations, and keeps both sides' delivery state
 * honest.
 */
class NodeSyncService
{
    public const AUDIT_REFUSED = 'node_sync.refused';

    public function __construct(
        private readonly NodeOperationReceiver $receiver,
        private readonly NodeSyncOutbox $outbox,
        private readonly NodeSignatureAlgorithm $algorithm,
        private readonly NodeKeyProvider $keys,
        private readonly NodeSetupService $nodes,
        private readonly AuditService $audit,
    ) {}

    /**
     * Run one exchange on behalf of a peer node.
     *
     * @throws NodeSyncException
     */
    public function exchange(NodeSyncRequest $request): NodeSyncResponse
    {
        $localNode = $this->nodes->activeNode();

        if (! $localNode instanceof Node) {
            throw NodeSyncException::nodeNotConfigured();
        }

        $peer = $this->authenticate($request, $localNode);

        // The caller's report on the previous exchange is settled first, so
        // operations it already holds or has permanently refused are out of the
        // outbox before this node decides what to offer next.
        $acknowledged = $this->outbox->markSent($localNode, $request->acknowledged);
        $refusalsRecorded = $this->outbox->markRefused($localNode, $request->refused);

        $results = $this->receiveAll($request, $localNode);

        $queued = $this->outbox->pendingFor($localNode, $peer);

        return NodeSyncResponse::for(
            node: $localNode,
            results: $results,
            operations: $this->outbox->envelopesFor($queued),
            acknowledged: $acknowledged,
            refusalsRecorded: $refusalsRecorded,
        );
    }

    /**
     * @return list<NodeSyncOperationResult>
     */
    private function receiveAll(NodeSyncRequest $request, Node $localNode): array
    {
        $results = [];

        foreach ($request->operations as $envelope) {
            try {
                $receipt = $this->receiver->receiveAndApply($envelope, $localNode);

                $results[] = $receipt->stored
                    ? NodeSyncOperationResult::stored($envelope->uuid())
                    : NodeSyncOperationResult::duplicate($envelope->uuid());
            } catch (NodeOperationRejectedException $rejection) {
                // The receive path already audited this refusal with its reason
                // code, so it is reported to the sender and the batch carries
                // on (technical spec 10.3).
                $results[] = NodeSyncOperationResult::refused($envelope->uuid(), $rejection);
            }
        }

        return $results;
    }

    /**
     * Establish that the caller is a paired peer node and that it signed this
     * exchange.
     *
     * @throws NodeSyncException
     */
    private function authenticate(NodeSyncRequest $request, Node $localNode): Node
    {
        $peer = Node::query()->find($request->sourceNodeId);

        if (! $peer instanceof Node) {
            throw $this->refuse($request, NodeSyncException::unknownSourceNode($request->sourceNodeId));
        }

        // This install's own node, a revoked peer, and a node record that never
        // completed pairing are all rejected: pairing is what establishes the
        // trust an exchange rests on (technical spec 7.3, 7.4).
        if ($peer->is_local
            || $peer->isRevoked()
            || $peer->paired_at === null
            || $peer->is($localNode)) {
            throw $this->refuse(
                $request,
                NodeSyncException::sourceNodeNotAccepted((string) $peer->node_name),
                $peer,
            );
        }

        // A captured exchange stays replayable for as long as its signature
        // verifies, so the signed `sent_at` bounds the window in which a
        // replayed pull could return this node's queue to whoever captured it.
        // The window is two-sided because a peer whose clock runs ahead is as
        // unverifiable as one whose clock runs behind.
        $age = CarbonImmutable::now()->utc()->diffInSeconds($request->sentAt, absolute: true);

        if ($age > $this->maxRequestAge()) {
            throw $this->refuse($request, NodeSyncException::staleRequest(), $peer);
        }

        $publicKey = $this->keys->publicKeyFor($peer);

        if ($publicKey === null
            || ! $this->algorithm->verify($request->canonicalPayload(), $request->signature, $publicKey)) {
            throw $this->refuse($request, NodeSyncException::signatureRejected(), $peer);
        }

        return $peer;
    }

    /**
     * Record a refused exchange and hand back the exception for the caller to
     * throw.
     *
     * A refused exchange stores nothing, so `node_operations` cannot be the
     * record of it. Without this audit event a peer could pull at this node
     * with an unverifiable signature, or after revocation, and leave no trace
     * anywhere (technical spec 23; data/API 14.1). What the exchange claimed
     * about itself goes in `after_json` rather than the audit event's scope
     * columns, because a refused exchange may name a node this install does not
     * have.
     */
    private function refuse(
        NodeSyncRequest $request,
        NodeSyncException $refusal,
        ?Node $peer = null,
    ): NodeSyncException {
        $this->audit->record(
            action: self::AUDIT_REFUSED,
            entityType: (new NodeOperation)->getMorphClass(),
            entityId: $request->sourceNodeId,
            actorNode: $peer,
            reason: $refusal->getMessage(),
            sourceContext: AuditEvent::SOURCE_SYNC,
            after: [
                'reason_code' => $refusal->reason,
                'source_node_id' => $request->sourceNodeId,
                'sent_at' => $request->sentAt->toIso8601String(),
                'operation_count' => count($request->operations),
                'acknowledged_count' => count($request->acknowledged),
                'refused_count' => count($request->refused),
            ],
        );

        return $refusal;
    }

    private function maxRequestAge(): int
    {
        return max(1, (int) config('meridian.node.sync.max_request_age_seconds', 300));
    }
}
