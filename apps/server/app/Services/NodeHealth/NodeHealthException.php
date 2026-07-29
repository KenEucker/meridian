<?php

declare(strict_types=1);

namespace App\Services\NodeHealth;

use RuntimeException;

/**
 * Refusals and failures on the node health report path (technical spec
 * 22A.11). Mirrors the node sync exception idiom: a machine-readable reason
 * plus an HTTP status for the endpoint to answer with.
 */
class NodeHealthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function missingField(string $field): self
    {
        return new self("The health report is missing {$field}.", 'invalid_report', 422);
    }

    public static function nodeNotConfigured(): self
    {
        return new self('This install has no configured node.', 'node_not_configured', 503);
    }

    public static function unknownSourceNode(string $nodeId): self
    {
        return new self("No paired node {$nodeId} is known here.", 'unknown_source_node', 403);
    }

    public static function sourceNodeNotAccepted(string $nodeName): self
    {
        return new self("Reports from {$nodeName} are not accepted.", 'source_node_not_accepted', 403);
    }

    public static function staleReport(): self
    {
        return new self('The report is too old or too far in the future to accept.', 'stale_report', 422);
    }

    public static function signatureRejected(): self
    {
        return new self('The report signature did not verify.', 'signature_rejected', 403);
    }

    public static function signingUnavailable(string $detail): self
    {
        return new self("This node cannot sign a health report: {$detail}", 'signing_unavailable', 503);
    }

    public static function peerUnreachable(string $detail): self
    {
        return new self("Central is unreachable: {$detail}", 'peer_unreachable', 502);
    }

    public static function peerRefused(string $detail): self
    {
        return new self("Central refused the report: {$detail}", 'peer_refused', 502);
    }
}
