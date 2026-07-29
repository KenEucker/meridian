<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Models\Node;
use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\Node\NodeKeyProvider;
use App\Services\Node\NodePairingState;
use App\Services\Node\NodeSetupService;
use Throwable;

/**
 * This node's identity, role, keys, and pairing state (SYS-033: node
 * category). Metrics Meridian cannot measure without privileged OS access are
 * reported as unknown rather than pretended (SYS-032).
 */
class NodeIdentityCheck implements DiagnosticCheck
{
    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodeKeyProvider $keys,
        private readonly NodePairingState $pairingState,
    ) {}

    public function key(): string
    {
        return 'node.identity';
    }

    public function label(): string
    {
        return 'Node identity and keys';
    }

    public function category(): string
    {
        return DiagnosticCategory::NODE;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node) {
            return DiagnosticResult::critical(
                'This install has no configured node.',
                [],
                'Complete first-run node setup or configure the node on Infrastructure -> Node Configuration.',
            );
        }

        $details = [
            'node_id' => (string) $node->getKey(),
            'node_name' => $node->node_name,
            'node_role' => $node->node_role,
            'event_locked' => $node->event_id !== null,
            'uptime' => 'unknown: Meridian does not have privileged host access',
            'memory_usage_bytes' => memory_get_usage(true),
        ];

        $problems = [];

        if (blank($node->node_name)) {
            $problems[] = 'The node has no name.';
        }

        if (blank($node->node_role)) {
            $problems[] = 'The node has no role.';
        }

        $details['has_private_key'] = $this->hasPrivateKey($node);
        $details['has_public_key'] = filled($node->public_key);

        if ($details['has_private_key'] !== true || $details['has_public_key'] !== true) {
            $problems[] = 'The node signing keypair is incomplete.';
        }

        try {
            $pairing = $this->pairingState->describe($node);
            $details['pairing_status'] = is_array($pairing) ? (string) ($pairing['status'] ?? 'unknown') : 'unknown';
        } catch (Throwable) {
            $details['pairing_status'] = 'unknown';
        }

        if ($problems !== []) {
            return DiagnosticResult::critical(
                implode(' ', $problems),
                $details,
                'Repair node identity on Infrastructure -> Node Configuration.',
            );
        }

        return DiagnosticResult::healthy('Node identity, role, and keys are in place.', $details);
    }

    private function hasPrivateKey(Node $node): ?bool
    {
        try {
            return $this->keys->hasPrivateKey($node);
        } catch (Throwable) {
            return null;
        }
    }
}
