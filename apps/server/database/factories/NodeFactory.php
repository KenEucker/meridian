<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeKeyProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    protected $model = Node::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_name' => fake()->unique()->domainName(),
            'node_role' => Node::ROLE_DEVELOPMENT,
            'is_local' => true,
            'public_key' => base64_encode(random_bytes(32)),
            'organization_id' => null,
            'event_id' => null,
            'central_node_url' => null,
            'paired_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * A peer node record learned through pairing rather than this install's own
     * node.
     */
    public function remote(): static
    {
        return $this->state(fn (): array => [
            'is_local' => false,
            'paired_at' => now(),
        ]);
    }

    public function standalone(): static
    {
        return $this->state(fn (): array => [
            'node_role' => Node::ROLE_STANDALONE,
        ]);
    }

    public function central(): static
    {
        return $this->state(fn (): array => [
            'node_role' => Node::ROLE_CENTRAL,
        ]);
    }

    public function onsite(): static
    {
        return $this->state(fn (): array => [
            'node_role' => Node::ROLE_ONSITE,
        ]);
    }

    /**
     * A node that can actually sign node operations: the stored public key
     * belongs to a real keypair, and the matching private key is recorded as
     * the node's `node_private_key` config value the way first-run setup
     * records it (technical spec 7.3, 10.4).
     *
     * Pass explicit key material to exercise a specific algorithm; the default
     * is whatever {@see NodeKeyPairGenerator} produces on this runtime.
     *
     * @param  array{public_key: string, private_key: string}|null  $keys
     */
    public function signing(?array $keys = null): static
    {
        $keys ??= app(NodeKeyPairGenerator::class)->generate();

        return $this
            ->state(fn (): array => ['public_key' => $keys['public_key']])
            ->afterCreating(function (Node $node) use ($keys): void {
                $node->configValues()->updateOrCreate(
                    ['key' => NodeKeyProvider::CONFIG_PRIVATE_KEY],
                    [
                        'value_json' => $keys['private_key'],
                        'source' => NodeConfigValue::SOURCE_DATABASE,
                    ],
                );
            });
    }
}
