<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NodeOperation>
 */
class NodeOperationFactory extends Factory
{
    protected $model = NodeOperation::class;

    /**
     * Define the model's default state.
     *
     * The operation type vocabulary belongs to the tasks that emit operations
     * (M12.4 onward), so the factory uses a neutral placeholder rather than
     * asserting an enumeration the specification does not define. `signature`
     * and `hash` are opaque placeholders here; real values arrive with
     * operation signing (M12.3).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => Node::factory()->onsite(),
            'target_node_id' => null,
            'actor_user_id' => User::factory(),
            'actor_device_id' => null,
            'operation_type' => 'upsert',
            'entity_type' => 'field_report',
            'entity_id' => (string) Str::uuid(),
            'event_id' => null,
            'created_at' => now(),
            'sent_at' => null,
            'received_at' => null,
            'applied_at' => null,
            'status' => NodeOperation::STATUS_PENDING,
            'signature' => base64_encode(random_bytes(64)),
            'hash' => hash('sha256', (string) Str::uuid()),
            'payload_json' => null,
            'failure_reason' => null,
            'retry_count' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NodeOperation::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    /**
     * Stored by the receiver but not yet applied (technical spec 10.1).
     */
    public function received(): static
    {
        return $this->state(fn (): array => [
            'status' => NodeOperation::STATUS_RECEIVED,
            'sent_at' => now(),
            'received_at' => now(),
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (): array => [
            'status' => NodeOperation::STATUS_APPLIED,
            'sent_at' => now(),
            'received_at' => now(),
            'applied_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Signature verification failed.'): static
    {
        return $this->state(fn (): array => [
            'status' => NodeOperation::STATUS_FAILED,
            'sent_at' => now(),
            'received_at' => now(),
            'failure_reason' => $reason,
            'retry_count' => 1,
        ]);
    }

    public function conflicted(): static
    {
        return $this->state(fn (): array => [
            'status' => NodeOperation::STATUS_CONFLICTED,
            'sent_at' => now(),
            'received_at' => now(),
        ]);
    }
}
