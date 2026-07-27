<?php

namespace App\Services\Node;

use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * One side of a node-to-node sync exchange, as it travels (technical spec 10.1,
 * 10.2).
 *
 * Node sync is bidirectional rather than push-only, and one exchange carries
 * both directions: the calling node pushes the operations it has queued in
 * `operations`, and the peer answers with the operations it has queued in
 * return. That shape follows from where the nodes sit. Central has a routable
 * address and on-site usually does not, so on-site initiates and central
 * replies; making the reply carry central's own operations is what keeps sync
 * bidirectional without central having to reach into the event network.
 *
 * The other two lists close the delivery loop for the previous exchange.
 * `acknowledged` names operations the caller now holds, which is how the peer
 * learns it can stop offering them. `refused` names operations the caller will
 * never accept, which is how the peer learns to stop offering them and to mark
 * its own copy failed instead of re-sending it every minute forever. Neither
 * list is required for correctness of the data — redelivery is safe because
 * `uuid` is the idempotency key (technical spec 10.1) — but without them a peer
 * cannot tell "delivered" from "not delivered yet".
 *
 * Authentication. The exchange is signed by the calling node over a canonical
 * payload and verified against the peer's registered public key, because the
 * response hands operations back: an unauthenticated caller could otherwise
 * pull this node's queue. Each operation inside `operations` carries its own
 * node signature and is verified separately on arrival (technical spec 10.4),
 * so the exchange signature covers the operation and acknowledgement
 * identifiers rather than their contents. `sent_at` bounds replay of a captured
 * exchange to a short window; the transport itself is HTTPS in event mode
 * (technical spec 8.2).
 */
final class NodeSyncRequest
{
    /**
     * Canonical payload format marker. Bump this only alongside a deliberate,
     * documented change to the signed encoding; every node must agree on it.
     */
    public const CANONICAL_FORMAT = 'meridian.node-sync.v1';

    /**
     * @param  list<NodeOperationEnvelope>  $operations
     * @param  list<string>  $acknowledged  uuids the caller now holds
     * @param  list<NodeSyncOperationResult>  $refused  operations the caller will not accept
     */
    private function __construct(
        public readonly string $sourceNodeId,
        public readonly CarbonImmutable $sentAt,
        public readonly array $operations,
        public readonly array $acknowledged,
        public readonly array $refused,
        public readonly string $signature,
    ) {}

    /**
     * @param  list<NodeOperationEnvelope>  $operations
     * @param  list<string>  $acknowledged
     * @param  list<NodeSyncOperationResult>  $refused
     */
    public static function create(
        Node $sourceNode,
        array $operations = [],
        array $acknowledged = [],
        array $refused = [],
        ?CarbonImmutable $sentAt = null,
    ): self {
        return new self(
            sourceNodeId: (string) $sourceNode->getKey(),
            sentAt: $sentAt ?? CarbonImmutable::now()->utc(),
            operations: array_values($operations),
            acknowledged: array_values($acknowledged),
            refused: array_values($refused),
            signature: '',
        );
    }

    public function signedWith(string $signature): self
    {
        return new self(
            sourceNodeId: $this->sourceNodeId,
            sentAt: $this->sentAt,
            operations: $this->operations,
            acknowledged: $this->acknowledged,
            refused: $this->refused,
            signature: $signature,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeSyncException
     */
    public static function fromArray(array $data): self
    {
        $sourceNodeId = $data['source_node_id'] ?? null;

        if (! is_string($sourceNodeId) || ! Str::isUuid(trim($sourceNodeId))) {
            throw NodeSyncException::invalidField('source_node_id');
        }

        $signature = $data['signature'] ?? null;

        if (! is_string($signature) || trim($signature) === '') {
            throw NodeSyncException::missingField('signature');
        }

        return new self(
            sourceNodeId: trim($sourceNodeId),
            sentAt: self::parseSentAt($data['sent_at'] ?? null),
            operations: self::parseOperations($data['operations'] ?? []),
            acknowledged: self::parseAcknowledged($data['acknowledged'] ?? []),
            refused: self::parseRefused($data['refused'] ?? []),
            signature: trim($signature),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_node_id' => $this->sourceNodeId,
            'sent_at' => $this->sentAt->utc()->toIso8601String(),
            'operations' => array_map(
                static fn (NodeOperationEnvelope $envelope): array => $envelope->toArray(),
                $this->operations,
            ),
            'acknowledged' => $this->acknowledged,
            'refused' => array_map(
                static fn (NodeSyncOperationResult $result): array => $result->toArray(),
                $this->refused,
            ),
            'signature' => $this->signature,
        ];
    }

    /**
     * The exact bytes the exchange signature covers: the format marker, a
     * newline, and the identifiers this exchange names, in a fixed order.
     *
     * Operation contents are not included because each operation carries its
     * own node signature over its own canonical payload and is verified on its
     * own (technical spec 10.4). Refusal reason text is not included either: it
     * is diagnostic, is stored as a readable failure reason rather than acted
     * on, and the transport is authenticated in event mode.
     */
    public function canonicalPayload(): string
    {
        $json = json_encode([
            'source_node_id' => $this->sourceNodeId,
            'sent_at' => $this->sentAt->utc()->toIso8601String(),
            'operations' => array_map(
                static fn (NodeOperationEnvelope $envelope): string => $envelope->uuid(),
                $this->operations,
            ),
            'acknowledged' => $this->acknowledged,
            'refused' => array_map(
                static fn (NodeSyncOperationResult $result): string => $result->uuid,
                $this->refused,
            ),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::CANONICAL_FORMAT."\n".$json;
    }

    public function isEmpty(): bool
    {
        return $this->operations === []
            && $this->acknowledged === []
            && $this->refused === [];
    }

    /**
     * @throws NodeSyncException
     */
    private static function parseSentAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw NodeSyncException::missingField('sent_at');
        }

        try {
            return CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            throw NodeSyncException::invalidField('sent_at');
        }
    }

    /**
     * @return list<NodeOperationEnvelope>
     *
     * @throws NodeSyncException
     */
    private static function parseOperations(mixed $value): array
    {
        if (! is_array($value)) {
            throw NodeSyncException::invalidField('operations');
        }

        $operations = [];

        foreach ($value as $operation) {
            if (! is_array($operation)) {
                throw NodeSyncException::invalidField('operations');
            }

            // A malformed envelope refuses the whole exchange rather than one
            // operation. A sending node only ever builds envelopes from rows it
            // already holds, so an unparseable one is corruption or a hostile
            // caller rather than the routine per-operation disagreement that is
            // reported and survived. Nothing is stored, so the sender's retry
            // is safe.
            try {
                $operations[] = NodeOperationEnvelope::fromArray($operation);
            } catch (NodeOperationRejectedException $rejection) {
                throw NodeSyncException::malformedRequest($rejection->getMessage());
            }
        }

        return $operations;
    }

    /**
     * @return list<string>
     *
     * @throws NodeSyncException
     */
    private static function parseAcknowledged(mixed $value): array
    {
        if (! is_array($value)) {
            throw NodeSyncException::invalidField('acknowledged');
        }

        $uuids = [];

        foreach ($value as $uuid) {
            if (! is_string($uuid) || ! Str::isUuid(trim($uuid))) {
                throw NodeSyncException::invalidField('acknowledged');
            }

            $uuids[] = trim($uuid);
        }

        return array_values(array_unique($uuids));
    }

    /**
     * @return list<NodeSyncOperationResult>
     *
     * @throws NodeSyncException
     */
    private static function parseRefused(mixed $value): array
    {
        if (! is_array($value)) {
            throw NodeSyncException::invalidField('refused');
        }

        $refused = [];

        foreach ($value as $result) {
            if (! is_array($result)) {
                throw NodeSyncException::invalidField('refused');
            }

            $refused[] = NodeSyncOperationResult::fromArray($result);
        }

        return $refused;
    }
}
