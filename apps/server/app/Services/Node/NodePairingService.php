<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodePairingToken;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Central-side redemption of a one-time node pairing token (technical spec
 * 7.3, 7.4).
 *
 * Redemption registers the pairing node as a peer `nodes` record on central,
 * marks the token used, and audits the pairing (data/API specification section
 * 8). The token is single use: replaying it with the same node identity returns
 * the original result so a lost response can be recovered, while replaying it
 * with a different node identity is refused.
 */
class NodePairingService
{
    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly NodePairingTokenService $tokens,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws NodePairingException
     */
    public function redeem(
        string $plaintextToken,
        string $nodeName,
        string $nodeRole,
        string $publicKey,
    ): NodePairingResult {
        $centralNode = $this->requireCentralNode();

        if (! in_array($nodeRole, Node::PAIRABLE_ROLES, true)) {
            throw NodePairingException::roleCannotPair($nodeRole);
        }

        $token = $this->tokens->findByPlaintext($plaintextToken);

        if (! $token instanceof NodePairingToken || $token->isRevoked() || $token->isExpired()) {
            throw NodePairingException::invalidToken();
        }

        if ($token->isUsed()) {
            return $this->replay($token, $centralNode, $nodeName, $publicKey);
        }

        $existing = Node::query()->where('node_name', $nodeName)->first();

        if ($existing instanceof Node) {
            if ($existing->is_local || ! hash_equals($existing->public_key, $publicKey)) {
                throw NodePairingException::nodeNameConflict($nodeName);
            }

            if ($existing->isRevoked()) {
                throw NodePairingException::nodeRevoked($nodeName);
            }
        }

        $pairedNode = DB::transaction(function () use ($token, $existing, $nodeName, $nodeRole, $publicKey): Node {
            // Re-read the token inside the transaction so two concurrent
            // redemptions of the same token cannot both pair a node.
            $locked = NodePairingToken::query()
                ->whereKey($token->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof NodePairingToken || ! $locked->isActive()) {
                throw NodePairingException::invalidToken();
            }

            $node = $existing ?? new Node;

            $node->forceFill([
                'node_name' => $nodeName,
                'node_role' => $nodeRole,
                'is_local' => false,
                'public_key' => $publicKey,
                'paired_at' => now(),
            ])->save();

            $locked->forceFill([
                'used_at' => now(),
                'paired_node_id' => $node->getKey(),
            ])->save();

            return $node;
        });

        $token->refresh();

        $this->audit->recordForEntity(
            entity: $pairedNode,
            action: 'node.paired',
            actorNode: $centralNode,
            after: [
                'node_name' => $pairedNode->node_name,
                'node_role' => $pairedNode->node_role,
                'paired_at' => $pairedNode->paired_at?->toIso8601String(),
                'pairing_token_id' => $token->getKey(),
            ],
            sourceContext: AuditEvent::SOURCE_API,
        );

        return new NodePairingResult($centralNode, $pairedNode, replayed: false);
    }

    /**
     * A token that already paired the same node returns its original result so
     * an on-site node that lost the response can finish pairing.
     *
     * @throws NodePairingException
     */
    private function replay(
        NodePairingToken $token,
        Node $centralNode,
        string $nodeName,
        string $publicKey,
    ): NodePairingResult {
        $pairedNode = $token->pairedNode()->first();

        if (! $pairedNode instanceof Node
            || $pairedNode->node_name !== $nodeName
            || ! hash_equals($pairedNode->public_key, $publicKey)) {
            throw NodePairingException::invalidToken();
        }

        if ($pairedNode->isRevoked()) {
            throw NodePairingException::nodeRevoked($nodeName);
        }

        return new NodePairingResult($centralNode, $pairedNode, replayed: true);
    }

    /**
     * @throws NodePairingException
     */
    private function requireCentralNode(): Node
    {
        $node = $this->nodes->activeNode();

        if (! $node instanceof Node) {
            throw NodePairingException::nodeNotConfigured();
        }

        if (! $node->isCentral()) {
            throw NodePairingException::notACentralNode();
        }

        return $node;
    }
}
