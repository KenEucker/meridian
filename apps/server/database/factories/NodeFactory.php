<?php

namespace Database\Factories;

use App\Models\Node;
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
}
