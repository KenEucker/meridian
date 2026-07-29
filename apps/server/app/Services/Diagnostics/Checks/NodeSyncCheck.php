<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Models\Node;
use App\Models\SyncConflict;
use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\Node\NodeSetupService;
use App\Services\Node\NodeSyncHealth;
use Throwable;

/**
 * Node-to-node sync state (SYS-033: sync category; SYS-036).
 *
 * An on-site node with a queue and no recent central contact is doing exactly
 * what it was deployed to do, so queued operations report as expected offline
 * operation rather than as a failure. Failures, refused exchanges, and open
 * sync conflicts are what ask for a human.
 */
class NodeSyncCheck implements DiagnosticCheck
{
    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodeSyncHealth $syncHealth,
    ) {}

    public function key(): string
    {
        return 'sync.node';
    }

    public function label(): string
    {
        return 'Node-to-node sync';
    }

    public function category(): string
    {
        return DiagnosticCategory::SYNC;
    }

    public function required(): bool
    {
        return false;
    }

    public function run(): DiagnosticResult
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node) {
            return DiagnosticResult::notApplicable('No node is configured, so there is nothing to sync.');
        }

        if (! in_array($node->node_role, Node::PAIRABLE_ROLES, true) && ! $node->isCentral()) {
            return DiagnosticResult::notApplicable(
                "A {$node->node_role} node does not participate in node-to-node sync.",
            );
        }

        $sync = $this->syncHealth->describe($node);
        $openConflicts = $this->openConflicts();

        $details = [
            'status' => $sync['status'],
            'queued' => $sync['queued'],
            'delivered' => $sync['delivered'],
            'undelivered' => $sync['undelivered'],
            'received' => $sync['received'],
            'applied' => $sync['applied'],
            'unapplied' => $sync['unapplied'],
            'oldest_queued_at' => $sync['oldest_queued_at'],
            'last_sent_at' => $sync['last_sent_at'],
            'last_received_at' => $sync['last_received_at'],
            'open_conflicts' => $openConflicts,
            'expected_offline' => $node->node_role === Node::ROLE_ONSITE && $sync['queued'] > 0,
        ];

        if ($sync['status'] === NodeSyncHealth::STATUS_ATTENTION || ($openConflicts ?? 0) > 0) {
            return DiagnosticResult::warning(
                'Node sync needs attention: failed operations, refused exchanges, or open conflicts exist.',
                $details,
                'Review Infrastructure -> Sync Conflicts and the node sync failures on Node Configuration.',
            );
        }

        if ($sync['status'] === NodeSyncHealth::STATUS_QUEUED) {
            return DiagnosticResult::healthy(
                $node->node_role === Node::ROLE_ONSITE
                    ? 'Operations are queued for central. Expected while this on-site node has no internet.'
                    : 'Operations are queued for the peer and will move on the next exchange.',
                $details,
            );
        }

        return DiagnosticResult::healthy('Node sync is moving normally or idle.', $details);
    }

    private function openConflicts(): ?int
    {
        try {
            return (int) SyncConflict::query()->open()->count();
        } catch (Throwable) {
            return null;
        }
    }
}
