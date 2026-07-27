<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodePairingToken;
use App\Services\Node\NodePairingTokenGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodePairingToken>
 */
class NodePairingTokenFactory extends Factory
{
    protected $model = NodePairingToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => NodePairingTokenGenerator::hash(NodePairingTokenGenerator::generate()),
            'issued_by_node_id' => Node::factory()->central(),
            'issued_by_user_id' => null,
            'label' => null,
            'expires_at' => null,
            'used_at' => null,
            'paired_node_id' => null,
            'revoked_at' => null,
        ];
    }

    public function forPlaintext(string $plaintext): static
    {
        return $this->state(fn (): array => [
            'token_hash' => NodePairingTokenGenerator::hash($plaintext),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (): array => [
            'used_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
