<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NodeSetupService
{
    /** A keypair was generated and stored for a node that had neither half. */
    public const KEYS_GENERATED = 'generated';

    /** The node already holds both halves; nothing was touched. */
    public const KEYS_PRESENT = 'present';

    /** The node holds one half and not the other; an operator has to decide. */
    public const KEYS_INCOMPLETE = 'incomplete';

    public function __construct(
        private readonly NodeKeyPairGenerator $keys,
        private readonly NodeKeyProvider $keyProvider,
    ) {}

    public function hasActiveNode(): bool
    {
        return Node::query()->active()->local()->exists();
    }

    /**
     * This install's own node. Peer node records created by pairing are
     * excluded, so learning about central never changes which node is ours.
     */
    public function activeNode(): ?Node
    {
        return Node::query()->active()->local()->latest('id')->first();
    }

    public function setupFirstNode(
        string $nodeName,
        string $nodeRole,
        ?string $centralNodeUrl = null,
        ?User $updatedBy = null
    ): Node {
        if (! in_array($nodeRole, Node::ROLES, true)) {
            throw new InvalidArgumentException('Unsupported node role.');
        }

        return DB::transaction(function () use ($nodeName, $nodeRole, $centralNodeUrl, $updatedBy): Node {
            if (Node::query()->active()->local()->lockForUpdate()->exists()) {
                throw new NodeAlreadyConfiguredException('This Meridian install already has an active node.');
            }

            $keypair = $this->keys->generate();

            $node = Node::query()->create([
                'node_name' => $nodeName,
                'node_role' => $nodeRole,
                'is_local' => true,
                'public_key' => $keypair['public_key'],
                'central_node_url' => $centralNodeUrl,
            ]);

            $this->storeDatabaseOverride($node, 'node_name', $nodeName, $updatedBy);
            $this->storeDatabaseOverride($node, 'node_role', $nodeRole, $updatedBy);
            $this->storeDatabaseOverride($node, 'node_public_key', $keypair['public_key'], $updatedBy);
            $this->storeDatabaseOverride($node, 'node_private_key', $keypair['private_key'], $updatedBy);

            if ($centralNodeUrl !== null && $centralNodeUrl !== '') {
                $this->storeDatabaseOverride($node, 'central_node_url', $centralNodeUrl, $updatedBy);
            }

            return $node->load('configValues');
        });
    }

    /**
     * Give an already-configured node the keypair it is missing (technical spec
     * 7.3, 7.4, 26.2).
     *
     * First-run setup generates keys with the node, so this covers the node that
     * arrived some other way: a database restored without its config values, an
     * install whose keys were never written, a deployment prepared before the
     * keys existed. It is what `meridian:secrets --generate` calls.
     *
     * An existing key is never replaced. Replacing it orphans every operation
     * this node has signed — central verifies an on-site node's operations
     * against the public key it registered at pairing — so a node holding
     * either half of a keypair is left exactly as it is. Holding one half and
     * not the other is reported as {@see self::KEYS_INCOMPLETE} rather than
     * repaired, because the repair is replacing a key somebody else may still
     * be verifying against, and that is an operator's decision.
     *
     * @return self::KEYS_* What was done.
     */
    public function ensureNodeKeys(Node $node, ?User $updatedBy = null): string
    {
        if (! $node->is_local) {
            throw new InvalidArgumentException('Only this install\'s own node may be given keys.');
        }

        return DB::transaction(function () use ($node, $updatedBy): string {
            $hasPrivateKey = $this->keyProvider->hasPrivateKey($node);
            $hasPublicKey = $this->keyProvider->publicKeyFor($node) !== null;

            if ($hasPrivateKey && $hasPublicKey) {
                return self::KEYS_PRESENT;
            }

            if ($hasPrivateKey || $hasPublicKey) {
                return self::KEYS_INCOMPLETE;
            }

            $keypair = $this->keys->generate();

            $node->forceFill(['public_key' => $keypair['public_key']])->save();

            $this->storeDatabaseOverride($node, 'node_public_key', $keypair['public_key'], $updatedBy);
            $this->storeDatabaseOverride($node, 'node_private_key', $keypair['private_key'], $updatedBy);

            $node->load('configValues');

            return self::KEYS_GENERATED;
        });
    }

    private function storeDatabaseOverride(Node $node, string $key, mixed $value, ?User $updatedBy): NodeConfigValue
    {
        return $node->configValues()->create([
            'key' => $key,
            'value_json' => $value,
            'source' => NodeConfigValue::SOURCE_DATABASE,
            'updated_by_user_id' => $updatedBy?->id,
        ]);
    }
}
