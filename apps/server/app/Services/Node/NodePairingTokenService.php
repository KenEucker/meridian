<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodePairingToken;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issues and revokes the one-time pairing tokens a central node hands to an
 * on-site or standalone node (technical spec 7.3, 7.4).
 *
 * Token issue and revocation are node config changes and are audited
 * (data/API specification section 8).
 */
class NodePairingTokenService
{
    public function __construct(
        private readonly NodeSetupService $nodes,
        private readonly AuditService $audit,
    ) {}

    /**
     * Create a pairing token on this central node.
     *
     * @throws NodePairingException
     */
    public function issue(
        ?User $issuedBy = null,
        ?string $label = null,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): IssuedNodePairingToken {
        $centralNode = $this->requireCentralNode();

        $plaintext = NodePairingTokenGenerator::generate();

        $token = DB::transaction(fn (): NodePairingToken => NodePairingToken::query()->create([
            'token_hash' => NodePairingTokenGenerator::hash($plaintext),
            'issued_by_node_id' => $centralNode->getKey(),
            'issued_by_user_id' => $issuedBy?->getKey(),
            'label' => $label,
        ]));

        $this->audit->recordForEntity(
            entity: $token,
            action: 'node_pairing_token.issued',
            actorUser: $issuedBy,
            actorNode: $centralNode,
            after: [
                'issued_by_node_id' => $centralNode->getKey(),
                'label' => $label,
            ],
            sourceContext: $sourceContext,
        );

        return new IssuedNodePairingToken($token, $plaintext);
    }

    /**
     * Revoke an unused token so it can no longer pair a node.
     *
     * @throws NodePairingException
     */
    public function revoke(
        NodePairingToken $token,
        ?User $revokedBy = null,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): NodePairingToken {
        if ($token->isRevoked()) {
            return $token;
        }

        $token->forceFill(['revoked_at' => now()])->save();

        $this->audit->recordForEntity(
            entity: $token,
            action: 'node_pairing_token.revoked',
            actorUser: $revokedBy,
            actorNode: $this->nodes->activeNode(),
            after: ['revoked_at' => $token->revoked_at?->toIso8601String()],
            sourceContext: $sourceContext,
        );

        return $token;
    }

    /**
     * Tokens that can still be redeemed, newest first.
     *
     * @return Collection<int, NodePairingToken>
     */
    public function activeTokens(): Collection
    {
        $centralNode = $this->nodes->activeNode();

        if (! $centralNode instanceof Node) {
            return new Collection;
        }

        return NodePairingToken::query()
            ->active()
            ->where('issued_by_node_id', $centralNode->getKey())
            ->latest('created_at')
            ->get();
    }

    /**
     * Resolve a token by its plaintext value regardless of state. Redemption
     * decides what an already-used token means, so lookup stays permissive.
     */
    public function findByPlaintext(string $plaintext): ?NodePairingToken
    {
        return NodePairingToken::query()
            ->where('token_hash', NodePairingTokenGenerator::hash($plaintext))
            ->first();
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
