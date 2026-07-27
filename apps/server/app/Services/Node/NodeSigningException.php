<?php

namespace App\Services\Node;

use RuntimeException;

/**
 * Raised when this node cannot produce a node operation signature (technical
 * spec 10.4, 12.4).
 *
 * Signing failures are local problems: our own key material is missing or
 * unusable, the operation is not in a signable state, or a device-originated
 * operation did not arrive with a usable device signature. Verifying an
 * untrusted signature never raises; it returns false so the caller fails
 * closed. See {@see NodeOperationSigner}.
 */
class NodeSigningException extends RuntimeException
{
    public static function nodeNotConfigured(): self
    {
        return new self('This Meridian install has no active node to sign node operations with.');
    }

    public static function privateKeyUnavailable(string $nodeName): self
    {
        return new self(sprintf(
            'Node "%s" has no private key configured, so it cannot sign node operations.',
            $nodeName,
        ));
    }

    /**
     * Private keys only ever belong to this install's own node. A peer node
     * record learned through pairing carries a public key and nothing else.
     */
    public static function notALocalNode(string $nodeName): self
    {
        return new self(sprintf(
            'Node "%s" is a peer node record, so this install does not hold its private key.',
            $nodeName,
        ));
    }

    public static function unsupportedKeyMaterial(): self
    {
        return new self('Unsupported node key material; expected base64 Ed25519 keys or a PEM key.');
    }

    public static function signingFailed(): self
    {
        return new self('Unable to sign the node operation with the configured node key.');
    }

    /**
     * Signature and hash cannot be rewritten once an operation is recorded
     * (technical spec 10.4; data/API 13.3), so signing happens before insert.
     */
    public static function operationAlreadyRecorded(): self
    {
        return new self('Node operations are append-only and must be signed before they are recorded.');
    }

    public static function incompleteOperation(string $attribute): self
    {
        return new self(sprintf(
            'Node operations must have %s set before they are signed.',
            $attribute,
        ));
    }

    public static function originNodeMismatch(string $nodeName): self
    {
        return new self(sprintf(
            'This node operation originated on another node, so "%s" cannot sign it as the origin node.',
            $nodeName,
        ));
    }

    public static function operationNotRecorded(): self
    {
        return new self('Node operation signatures can only be audited after the operation is recorded.');
    }

    /**
     * The accepting node countersigns a device-originated operation only after
     * the device signature verifies (technical spec 12.4).
     */
    public static function deviceSignatureRejected(): self
    {
        return new self('The device signature on this node operation could not be verified.');
    }

    public static function deviceMismatch(): self
    {
        return new self('The countersigning device does not match the operation acting device.');
    }
}
