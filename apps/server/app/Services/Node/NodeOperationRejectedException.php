<?php

namespace App\Services\Node;

use RuntimeException;

/**
 * A remote node operation was refused before it was stored (technical spec
 * 10.1, 10.4; data/API 13.3).
 *
 * Refusal and failure are different outcomes and this exception only covers the
 * first. A refused operation is never written to `node_operations`: it is
 * malformed, unauthenticated, addressed elsewhere, or sent by a node or device
 * this install will not accept from, so there is nothing worth keeping in an
 * append-only log. A failure, by contrast, happens to an operation that was
 * accepted and stored, and is recorded on the row itself as `failed` with a
 * `failure_reason` and a `retry_count` so it stays recoverable.
 *
 * Each instance carries a stable reason code so the sync loop (M12.5), God mode,
 * and Electron health (M12.10) can explain the same refusal without parsing
 * message text.
 */
class NodeOperationRejectedException extends RuntimeException
{
    /** The envelope is missing a required field or carries an unusable value. */
    public const REASON_MALFORMED = 'malformed_operation';

    /** This install has no configured node to receive on. */
    public const REASON_NODE_NOT_CONFIGURED = 'node_not_configured';

    /** The operation names a target node that is not this node. */
    public const REASON_WRONG_TARGET_NODE = 'wrong_target_node';

    /** The origin node is not a node this install knows about. */
    public const REASON_UNKNOWN_ORIGIN_NODE = 'unknown_origin_node';

    /** The origin node record is revoked on this node. */
    public const REASON_ORIGIN_NODE_REVOKED = 'origin_node_revoked';

    /** The acting user is not known to this node. */
    public const REASON_UNKNOWN_ACTOR_USER = 'unknown_actor_user';

    /** The acting device is not known to this node. */
    public const REASON_UNKNOWN_ACTOR_DEVICE = 'unknown_actor_device';

    /** The acting device is revoked on this node. */
    public const REASON_ACTOR_DEVICE_REVOKED = 'actor_device_revoked';

    /** The node signature over the canonical payload did not verify. */
    public const REASON_SIGNATURE_REJECTED = 'signature_rejected';

    /** The idempotency key is already held by an operation with other content. */
    public const REASON_UUID_CONFLICT = 'operation_uuid_conflict';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $detail): self
    {
        return new self(self::REASON_MALFORMED, $detail);
    }

    public static function missingField(string $field): self
    {
        return self::malformed(sprintf('The node operation is missing %s.', $field));
    }

    public static function invalidField(string $field): self
    {
        return self::malformed(sprintf('The node operation field %s is not a usable value.', $field));
    }

    public static function nodeNotConfigured(): self
    {
        return new self(
            self::REASON_NODE_NOT_CONFIGURED,
            'This Meridian install has no configured node, so it cannot receive node operations.',
        );
    }

    public static function wrongTargetNode(): self
    {
        return new self(
            self::REASON_WRONG_TARGET_NODE,
            'The node operation is addressed to a different node.',
        );
    }

    public static function unknownOriginNode(string $originNodeId): self
    {
        return new self(
            self::REASON_UNKNOWN_ORIGIN_NODE,
            sprintf('The node operation origin node %s is not known to this node.', $originNodeId),
        );
    }

    public static function originNodeRevoked(string $nodeName): self
    {
        return new self(
            self::REASON_ORIGIN_NODE_REVOKED,
            sprintf('The node operation origin node "%s" is revoked on this node.', $nodeName),
        );
    }

    public static function unknownActorUser(string $userId): self
    {
        return new self(
            self::REASON_UNKNOWN_ACTOR_USER,
            sprintf('The node operation acting user %s is not known to this node.', $userId),
        );
    }

    public static function unknownActorDevice(string $deviceId): self
    {
        return new self(
            self::REASON_UNKNOWN_ACTOR_DEVICE,
            sprintf('The node operation acting device %s is not known to this node.', $deviceId),
        );
    }

    public static function actorDeviceRevoked(string $deviceId): self
    {
        return new self(
            self::REASON_ACTOR_DEVICE_REVOKED,
            sprintf('The node operation acting device %s is revoked on this node.', $deviceId),
        );
    }

    public static function signatureRejected(): self
    {
        return new self(
            self::REASON_SIGNATURE_REJECTED,
            'The node signature on this node operation could not be verified.',
        );
    }

    /**
     * `uuid` is the idempotency key, so the same key arriving with different
     * content is a replay rather than a redelivery. The stored operation stands
     * and the new content is refused, because a stored operation is append-only.
     */
    public static function uuidConflict(string $uuid): self
    {
        return new self(
            self::REASON_UUID_CONFLICT,
            sprintf(
                'A different node operation is already stored under uuid %s; the stored operation is authoritative.',
                $uuid,
            ),
        );
    }
}
