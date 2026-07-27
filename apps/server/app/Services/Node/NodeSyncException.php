<?php

namespace App\Services\Node;

use RuntimeException;

/**
 * A node-to-node sync exchange could not run or was refused (technical spec
 * 10.1, 10.2).
 *
 * This covers the exchange, not the operations inside it. An individual
 * operation that is refused on arrival raises
 * {@see NodeOperationRejectedException} and is reported per operation, because
 * one unacceptable operation must not stop the rest of a sync run (technical
 * spec 10.3). This exception is for the cases where there is no run at all: the
 * caller cannot be authenticated, the peer cannot be reached, or this node is
 * not configured to sync.
 *
 * Each instance carries a stable reason code so the sync endpoint, the sync
 * client, the artisan command, and Electron health (M12.10) explain the same
 * failure without parsing message text.
 */
class NodeSyncException extends RuntimeException
{
    /** This install has no configured node. */
    public const REASON_NODE_NOT_CONFIGURED = 'node_not_configured';

    /** This node role does not initiate sync with a central node. */
    public const REASON_ROLE_CANNOT_SYNC = 'role_cannot_sync';

    /** This node has not completed pairing, or its pairing needs a recheck. */
    public const REASON_NOT_PAIRED = 'not_paired';

    /** No central node URL is configured on this node. */
    public const REASON_CENTRAL_URL_MISSING = 'central_node_url_missing';

    /** Event mode refuses plain HTTP for node-to-node traffic. */
    public const REASON_INSECURE_CENTRAL_URL = 'insecure_central_node_url';

    /** This node cannot sign the exchange, so it cannot prove who it is. */
    public const REASON_SIGNING_UNAVAILABLE = 'sync_signing_unavailable';

    /** The peer node could not be reached. */
    public const REASON_PEER_UNREACHABLE = 'peer_node_unreachable';

    /** The peer refused the exchange or returned an unusable response. */
    public const REASON_PEER_REFUSED = 'peer_node_refused';

    /** The request body is missing a field or carries an unusable value. */
    public const REASON_MALFORMED_REQUEST = 'malformed_sync_request';

    /** The calling node is not a node this install knows about. */
    public const REASON_UNKNOWN_SOURCE_NODE = 'unknown_source_node';

    /** The calling node record is revoked or was never paired here. */
    public const REASON_SOURCE_NODE_NOT_ACCEPTED = 'source_node_not_accepted';

    /** The signature over the exchange did not verify against the peer key. */
    public const REASON_SIGNATURE_REJECTED = 'sync_signature_rejected';

    /** The request is older or further ahead than the accepted clock window. */
    public const REASON_STALE_REQUEST = 'stale_sync_request';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function nodeNotConfigured(): self
    {
        return new self(
            self::REASON_NODE_NOT_CONFIGURED,
            'This Meridian install has no configured node, so it cannot sync node operations.',
            409,
        );
    }

    public static function roleCannotSync(string $role): self
    {
        return new self(
            self::REASON_ROLE_CANNOT_SYNC,
            sprintf('A %s node does not sync with central. Only on-site and standalone nodes do.', $role),
            409,
        );
    }

    public static function notPaired(string $status): self
    {
        return new self(
            self::REASON_NOT_PAIRED,
            sprintf('This node is not paired with central (%s), so there is no peer to sync with.', $status),
            409,
        );
    }

    public static function centralUrlMissing(): self
    {
        return new self(
            self::REASON_CENTRAL_URL_MISSING,
            'No central node URL is configured. Set the central node URL before syncing.',
            422,
        );
    }

    public static function insecureCentralUrl(): self
    {
        return new self(
            self::REASON_INSECURE_CENTRAL_URL,
            'Event mode requires HTTPS for the central node URL. Syncing over plain HTTP is refused.',
            422,
        );
    }

    public static function signingUnavailable(string $detail): self
    {
        return new self(
            self::REASON_SIGNING_UNAVAILABLE,
            sprintf('This node cannot sign a sync exchange: %s', $detail),
            409,
        );
    }

    public static function peerUnreachable(string $detail): self
    {
        return new self(
            self::REASON_PEER_UNREACHABLE,
            sprintf('The peer node could not be reached: %s', $detail),
            502,
        );
    }

    public static function peerRefused(string $detail): self
    {
        return new self(
            self::REASON_PEER_REFUSED,
            sprintf('The peer node refused the sync exchange: %s', $detail),
            502,
        );
    }

    public static function malformedRequest(string $detail): self
    {
        return new self(self::REASON_MALFORMED_REQUEST, $detail, 422);
    }

    public static function missingField(string $field): self
    {
        return self::malformedRequest(sprintf('The sync exchange is missing %s.', $field));
    }

    public static function invalidField(string $field): self
    {
        return self::malformedRequest(sprintf('The sync exchange field %s is not a usable value.', $field));
    }

    public static function unknownSourceNode(string $nodeId): self
    {
        return new self(
            self::REASON_UNKNOWN_SOURCE_NODE,
            sprintf('The calling node %s is not known to this node.', $nodeId),
            401,
        );
    }

    /**
     * A node record that is this install's own, revoked, or never paired is not
     * a peer this node exchanges operations with. Pairing is what establishes
     * the trust an exchange rests on (technical spec 7.3, 7.4).
     */
    public static function sourceNodeNotAccepted(string $nodeName): self
    {
        return new self(
            self::REASON_SOURCE_NODE_NOT_ACCEPTED,
            sprintf('The node "%s" is not a paired peer this node accepts sync from.', $nodeName),
            401,
        );
    }

    public static function signatureRejected(): self
    {
        return new self(
            self::REASON_SIGNATURE_REJECTED,
            'The signature over this sync exchange could not be verified.',
            401,
        );
    }

    public static function staleRequest(): self
    {
        return new self(
            self::REASON_STALE_REQUEST,
            'The sync exchange is outside the accepted time window and was refused.',
            401,
        );
    }
}
