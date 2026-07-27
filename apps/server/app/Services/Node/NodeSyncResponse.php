<?php

namespace App\Services\Node;

use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The answering half of a node-to-node sync exchange (technical spec 10.1).
 *
 * `results` reports what happened to each operation the caller pushed, and
 * `operations` carries this node's own queued operations back in the same round
 * trip, which is what makes the exchange bidirectional rather than push-only.
 *
 * The response is not signed as a whole. Every operation it carries is
 * individually node-signed and is verified by the receiving node before it is
 * stored (technical spec 10.4), so the part of the response that changes state
 * authenticates itself. The rest — delivery results and counts — only updates
 * the caller's own bookkeeping about operations it already holds, and the
 * transport is HTTPS in event mode (technical spec 8.2).
 */
final class NodeSyncResponse
{
    /**
     * @param  list<NodeSyncOperationResult>  $results  outcomes for pushed operations
     * @param  list<NodeOperationEnvelope>  $operations  this node's queued operations
     * @param  int  $acknowledged  operations the caller confirmed it now holds
     * @param  int  $refusalsRecorded  operations the caller will never accept
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly CarbonImmutable $receivedAt,
        public readonly array $results,
        public readonly array $operations,
        public readonly int $acknowledged = 0,
        public readonly int $refusalsRecorded = 0,
    ) {}

    /**
     * @param  list<NodeSyncOperationResult>  $results
     * @param  list<NodeOperationEnvelope>  $operations
     */
    public static function for(
        Node $node,
        array $results,
        array $operations,
        int $acknowledged = 0,
        int $refusalsRecorded = 0,
    ): self {
        return new self(
            nodeId: (string) $node->getKey(),
            receivedAt: CarbonImmutable::now()->utc(),
            results: array_values($results),
            operations: array_values($operations),
            acknowledged: $acknowledged,
            refusalsRecorded: $refusalsRecorded,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeSyncException
     */
    public static function fromArray(array $data): self
    {
        $nodeId = $data['node_id'] ?? null;

        if (! is_string($nodeId) || ! Str::isUuid(trim($nodeId))) {
            throw NodeSyncException::peerRefused('the sync response did not name a usable node id.');
        }

        return new self(
            nodeId: trim($nodeId),
            receivedAt: self::parseReceivedAt($data['received_at'] ?? null),
            results: self::parseResults($data['results'] ?? []),
            operations: self::parseOperations($data['operations'] ?? []),
            acknowledged: (int) ($data['acknowledged'] ?? 0),
            refusalsRecorded: (int) ($data['refusals_recorded'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'received_at' => $this->receivedAt->utc()->toIso8601String(),
            'results' => array_map(
                static fn (NodeSyncOperationResult $result): array => $result->toArray(),
                $this->results,
            ),
            'operations' => array_map(
                static fn (NodeOperationEnvelope $envelope): array => $envelope->toArray(),
                $this->operations,
            ),
            'acknowledged' => $this->acknowledged,
            'refusals_recorded' => $this->refusalsRecorded,
        ];
    }

    public function resultFor(string $uuid): ?NodeSyncOperationResult
    {
        foreach ($this->results as $result) {
            if ($result->uuid === $uuid) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @throws NodeSyncException
     */
    private static function parseReceivedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw NodeSyncException::peerRefused('the sync response did not carry a receipt time.');
        }

        try {
            return CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            throw NodeSyncException::peerRefused('the sync response receipt time was not understood.');
        }
    }

    /**
     * @return list<NodeSyncOperationResult>
     *
     * @throws NodeSyncException
     */
    private static function parseResults(mixed $value): array
    {
        if (! is_array($value)) {
            throw NodeSyncException::peerRefused('the sync response results were not understood.');
        }

        $results = [];

        foreach ($value as $result) {
            if (! is_array($result)) {
                throw NodeSyncException::peerRefused('the sync response results were not understood.');
            }

            $results[] = NodeSyncOperationResult::fromArray($result);
        }

        return $results;
    }

    /**
     * @return list<NodeOperationEnvelope>
     *
     * @throws NodeSyncException
     */
    private static function parseOperations(mixed $value): array
    {
        if (! is_array($value)) {
            throw NodeSyncException::peerRefused('the sync response operations were not understood.');
        }

        $operations = [];

        foreach ($value as $operation) {
            if (! is_array($operation)) {
                throw NodeSyncException::peerRefused('the sync response operations were not understood.');
            }

            try {
                $operations[] = NodeOperationEnvelope::fromArray($operation);
            } catch (NodeOperationRejectedException $rejection) {
                throw NodeSyncException::peerRefused($rejection->getMessage());
            }
        }

        return $operations;
    }
}
