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
 *
 * The peer record keeps the pairing node's own id rather than minting a new
 * one. Node ids are global, not per-install, because a node operation names its
 * origin and target nodes by id inside the message a signature covers
 * (technical spec 10.4): if central knew an on-site node by a different id than
 * the on-site node knows itself by, every operation that node signed would
 * arrive naming an origin central cannot resolve, and the id could not be
 * rewritten without breaking the signature.
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
        string $nodeId,
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
            return $this->replay($token, $centralNode, $nodeId, $nodeName, $publicKey);
        }

        $existing = $this->existingPeer($nodeId, $nodeName, $publicKey);

        $pairedNode = DB::transaction(function () use ($token, $existing, $nodeId, $nodeName, $nodeRole, $publicKey): Node {
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
                'id' => $nodeId,
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
        string $nodeId,
        string $nodeName,
        string $publicKey,
    ): NodePairingResult {
        $pairedNode = $token->pairedNode()->first();

        if (! $pairedNode instanceof Node
            || (string) $pairedNode->getKey() !== $nodeId
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
     * The peer record this pairing updates, or null when the node is new here.
     *
     * A pairing node is identified by its id, its name, and its key material
     * together, and all three have to agree with whatever this install already
     * holds. Any disagreement is refused rather than reconciled: adopting a
     * peer-supplied id means a node presenting a valid token could otherwise
     * claim an identity that already belongs to someone else, including
     * central's own.
     *
     * @throws NodePairingException
     */
    private function existingPeer(string $nodeId, string $nodeName, string $publicKey): ?Node
    {
        $byId = Node::query()->find($nodeId);
        $byName = Node::query()->where('node_name', $nodeName)->first();

        if ($byId instanceof Node
            && ($byId->is_local
                || $byId->node_name !== $nodeName
                || ! hash_equals((string) $byId->public_key, $publicKey))) {
            throw NodePairingException::nodeIdConflict($nodeId);
        }

        if ($byName instanceof Node) {
            if ($byName->is_local || ! hash_equals((string) $byName->public_key, $publicKey)) {
                throw NodePairingException::nodeNameConflict($nodeName);
            }

            if ((string) $byName->getKey() !== $nodeId) {
                throw NodePairingException::nodeIdConflict($nodeId);
            }
        }

        $existing = $byId ?? $byName;

        if ($existing instanceof Node && $existing->isRevoked()) {
            throw NodePairingException::nodeRevoked($nodeName);
        }

        return $existing;
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
