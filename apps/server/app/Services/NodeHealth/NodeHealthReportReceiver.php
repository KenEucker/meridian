<?php

declare(strict_types=1);

namespace App\Services\NodeHealth;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeHealthReport;
use App\Services\Audit\AuditService;
use App\Services\Node\NodeKeyProvider;
use App\Services\Node\NodeSetupService;
use App\Services\Node\NodeSignatureAlgorithm;
use Carbon\CarbonImmutable;

/**
 * Verifies and stores health reports from paired nodes (technical spec
 * 22A.11; SYS-037, SYS-038).
 *
 * Authentication mirrors the node sync exchange: the report must name a
 * paired, unrevoked peer, be fresh within the same replay window sync uses,
 * and carry a signature that verifies against the public key learned at
 * pairing. Anything else is refused and audited; an unverified report is
 * never stored.
 */
class NodeHealthReportReceiver
{
    public const AUDIT_REFUSED = 'node_health_report.refused';

    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodeKeyProvider $keys,
        private readonly NodeSignatureAlgorithm $algorithm,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws NodeHealthException
     */
    public function receive(NodeHealthReportPayload $payload): NodeHealthReport
    {
        $localNode = $this->nodes->activeNode();

        if (! $localNode instanceof Node) {
            throw NodeHealthException::nodeNotConfigured();
        }

        $peer = Node::query()->find($payload->sourceNodeId);

        if (! $peer instanceof Node) {
            throw $this->refuse($payload, NodeHealthException::unknownSourceNode($payload->sourceNodeId));
        }

        if ($peer->is_local
            || $peer->isRevoked()
            || $peer->paired_at === null
            || $peer->is($localNode)) {
            throw $this->refuse($payload, NodeHealthException::sourceNodeNotAccepted((string) $peer->node_name), $peer);
        }

        $age = CarbonImmutable::now()->utc()->diffInSeconds($payload->generatedAt, absolute: true);

        if ($age > (int) config('meridian.node.sync.max_request_age_seconds', 300)) {
            throw $this->refuse($payload, NodeHealthException::staleReport(), $peer);
        }

        $publicKey = $this->keys->publicKeyFor($peer);

        if ($publicKey === null
            || ! $this->algorithm->verify($payload->canonicalPayload(), $payload->signature, $publicKey)) {
            throw $this->refuse($payload, NodeHealthException::signatureRejected(), $peer);
        }

        return $this->store($peer, $payload);
    }

    /**
     * Store a report for a node without wire authentication — the local
     * node's own report, produced in-process.
     */
    public function store(Node $node, NodeHealthReportPayload $payload): NodeHealthReport
    {
        return NodeHealthReport::query()->updateOrCreate(
            ['node_id' => $node->getKey()],
            [
                'report_uuid' => $payload->reportUuid,
                'overall_status' => $payload->overallStatus,
                'node_name' => $payload->nodeName,
                'node_role' => $payload->nodeRole,
                'meridian_version' => $payload->meridianVersion,
                'config_schema_version' => $payload->configSchemaVersion,
                'category_statuses_json' => $payload->categoryStatuses,
                'summary_json' => $payload->summary,
                'warnings_json' => $payload->warnings,
                'generated_at' => $payload->generatedAt,
                'received_at' => CarbonImmutable::now(),
            ],
        );
    }

    private function refuse(
        NodeHealthReportPayload $payload,
        NodeHealthException $refusal,
        ?Node $peer = null,
    ): NodeHealthException {
        $this->audit->record(
            action: self::AUDIT_REFUSED,
            entityType: (new NodeHealthReport)->getMorphClass(),
            entityId: $payload->reportUuid,
            actorNode: $peer,
            reason: $refusal->getMessage(),
            sourceContext: AuditEvent::SOURCE_SYNC,
            after: [
                'reason_code' => $refusal->reason,
                'source_node_id' => $payload->sourceNodeId,
                'generated_at' => $payload->generatedAt->toIso8601String(),
            ],
        );

        return $refusal;
    }
}
