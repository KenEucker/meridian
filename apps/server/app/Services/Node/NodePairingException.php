<?php

namespace App\Services\Node;

use RuntimeException;

/**
 * A node pairing attempt was refused. Each instance carries a stable reason
 * code so the central API, the on-site pairing client, and God mode can all
 * explain the same refusal.
 */
class NodePairingException extends RuntimeException
{
    /** This install has no configured node yet. */
    public const REASON_NODE_NOT_CONFIGURED = 'node_not_configured';

    /** Pairing tokens are issued by, and redeemed on, a central node only. */
    public const REASON_NOT_A_CENTRAL_NODE = 'not_a_central_node';

    /** The redeeming node role does not pair with central. */
    public const REASON_ROLE_CANNOT_PAIR = 'role_cannot_pair';

    /** The token is unknown, already used by another node, revoked, or expired. */
    public const REASON_INVALID_TOKEN = 'invalid_pairing_token';

    /** A different node already holds the submitted node name. */
    public const REASON_NODE_NAME_CONFLICT = 'node_name_conflict';

    /** A different node identity already holds the submitted node id. */
    public const REASON_NODE_ID_CONFLICT = 'node_id_conflict';

    /** The peer node record was revoked on this node. */
    public const REASON_NODE_REVOKED = 'node_revoked';

    /** No central node URL is configured on this node. */
    public const REASON_CENTRAL_URL_MISSING = 'central_node_url_missing';

    /** Event mode refuses plain HTTP for node-to-node traffic. */
    public const REASON_INSECURE_CENTRAL_URL = 'insecure_central_node_url';

    /** The central node could not be reached. */
    public const REASON_CENTRAL_UNREACHABLE = 'central_node_unreachable';

    /** The central node refused or returned an unusable pairing response. */
    public const REASON_CENTRAL_REFUSED = 'central_node_refused';

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
            'This Meridian install has no configured node. Complete node setup before pairing.',
            409,
        );
    }

    public static function notACentralNode(): self
    {
        return new self(
            self::REASON_NOT_A_CENTRAL_NODE,
            'This Meridian install is not a central node, so it cannot issue or accept pairing tokens.',
            409,
        );
    }

    public static function roleCannotPair(string $role): self
    {
        return new self(
            self::REASON_ROLE_CANNOT_PAIR,
            sprintf('A %s node does not pair with central. Only on-site and standalone nodes pair.', $role),
            422,
        );
    }

    public static function invalidToken(): self
    {
        return new self(
            self::REASON_INVALID_TOKEN,
            'The pairing token is not valid. Pairing tokens are single use and may have been used, revoked, or expired.',
            401,
        );
    }

    public static function nodeNameConflict(string $nodeName): self
    {
        return new self(
            self::REASON_NODE_NAME_CONFLICT,
            sprintf('Another node is already registered as "%s" with different key material.', $nodeName),
            409,
        );
    }

    /**
     * Node ids are global rather than per-install: a node keeps the same
     * identifier on every node that knows it, because node operations name
     * their origin node by id inside the signed message (technical spec 10.4).
     * An id already held by a different node identity therefore cannot be
     * adopted.
     */
    public static function nodeIdConflict(string $nodeId): self
    {
        return new self(
            self::REASON_NODE_ID_CONFLICT,
            sprintf('The node id %s is already held by a different node on this install.', $nodeId),
            409,
        );
    }

    public static function nodeRevoked(string $nodeName): self
    {
        return new self(
            self::REASON_NODE_REVOKED,
            sprintf('The node "%s" is revoked and cannot be paired until it is restored.', $nodeName),
            409,
        );
    }

    public static function centralUrlMissing(): self
    {
        return new self(
            self::REASON_CENTRAL_URL_MISSING,
            'No central node URL is configured. Set the central node URL before pairing.',
            422,
        );
    }

    public static function insecureCentralUrl(): self
    {
        return new self(
            self::REASON_INSECURE_CENTRAL_URL,
            'Event mode requires HTTPS for the central node URL. Pairing over plain HTTP is refused.',
            422,
        );
    }

    public static function centralUnreachable(string $detail): self
    {
        return new self(
            self::REASON_CENTRAL_UNREACHABLE,
            sprintf('The central node could not be reached: %s', $detail),
            502,
        );
    }

    public static function centralRefused(string $detail): self
    {
        return new self(
            self::REASON_CENTRAL_REFUSED,
            sprintf('The central node refused pairing: %s', $detail),
            502,
        );
    }
}
