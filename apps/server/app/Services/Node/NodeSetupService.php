<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NodeSetupService
{
    public function __construct(private readonly NodeKeyPairGenerator $keys) {}

    public function hasActiveNode(): bool
    {
        return Node::query()->active()->exists();
    }

    public function activeNode(): ?Node
    {
        return Node::query()->active()->latest('id')->first();
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
            if (Node::query()->active()->lockForUpdate()->exists()) {
                throw new NodeAlreadyConfiguredException('This Meridian install already has an active node.');
            }

            $keypair = $this->keys->generate();

            $node = Node::query()->create([
                'node_name' => $nodeName,
                'node_role' => $nodeRole,
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
