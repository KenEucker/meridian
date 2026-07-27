<?php

namespace App\Services\Node;

use App\Models\NodeOperation;
use Carbon\CarbonImmutable;

/**
 * The part of a node operation a signature actually covers (technical spec
 * 10.4; data/API 13.3).
 *
 * Operation signatures cover the normalized operation fields and nothing else.
 * `payload_json` is outside the signed message, which means a peer, or anything
 * sitting between two peers, can change the payload without breaking
 * verification. An applier that read the payload would therefore be writing
 * local state from unauthenticated input on an operation that verified
 * perfectly.
 *
 * This projection is how that is prevented rather than merely discouraged.
 * Appliers receive these fields and have no access to the payload at all, so
 * every applied change is derived from content the origin node signed.
 *
 * The practical consequence is that an operation's meaning has to live in
 * `operation_type` together with the entity it names. An operation vocabulary
 * that needs to carry a value must express it as a command-style operation type
 * rather than as payload data.
 */
final class SignedNodeOperation
{
    private function __construct(
        /** The idempotency key (technical spec 10.1). */
        public readonly string $uuid,
        public readonly string $originNodeId,
        public readonly ?string $targetNodeId,
        public readonly string $actorUserId,
        public readonly ?string $actorDeviceId,
        public readonly string $operationType,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly ?string $eventId,
        /** The origin node's creation time, in UTC. */
        public readonly CarbonImmutable $createdAt,
    ) {}

    public static function fromOperation(NodeOperation $operation): self
    {
        $normalized = $operation->normalizedAttributes();

        return new self(
            uuid: (string) $normalized['uuid'],
            originNodeId: (string) $normalized['origin_node_id'],
            targetNodeId: $normalized['target_node_id'],
            actorUserId: (string) $normalized['actor_user_id'],
            actorDeviceId: $normalized['actor_device_id'],
            operationType: (string) $normalized['operation_type'],
            entityType: (string) $normalized['entity_type'],
            entityId: (string) $normalized['entity_id'],
            eventId: $normalized['event_id'],
            createdAt: CarbonImmutable::parse((string) $normalized['created_at'])->utc(),
        );
    }

    /**
     * The signed fields in specification order, matching the projection the
     * signature was made over.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'origin_node_id' => $this->originNodeId,
            'target_node_id' => $this->targetNodeId,
            'actor_user_id' => $this->actorUserId,
            'actor_device_id' => $this->actorDeviceId,
            'operation_type' => $this->operationType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'event_id' => $this->eventId,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}
