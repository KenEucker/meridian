<?php

namespace Database\Factories;

use App\Models\NodeOperation;
use App\Models\SyncConflict;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SyncConflict>
 */
class SyncConflictFactory extends Factory
{
    protected $model = SyncConflict::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $entityType = 'field_report';
        $entityId = (string) Str::uuid();

        return [
            'operation_id' => NodeOperation::factory()->conflicted()->state([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]),
            'conflict_type' => SyncConflict::TYPE_STATE_MISMATCH,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'local_value_json' => ['status' => 'open'],
            'remote_value_json' => ['status' => 'closed'],
            'reason' => 'Local and remote entity state disagree.',
            'status' => SyncConflict::STATUS_OPEN,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'resolution' => null,
        ];
    }

    public function unresolved(): static
    {
        return $this->state(fn (): array => [
            'status' => SyncConflict::STATUS_OPEN,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'resolution' => null,
        ]);
    }

    public function open(): static
    {
        return $this->unresolved();
    }

    /**
     * A resolved conflict with review metadata filled. Resolution apply logic
     * belongs to M12.9; this state only seeds the review columns.
     */
    public function resolved(
        string $resolution = SyncConflict::RESOLUTION_ACCEPT_ONSITE,
        ?User $reviewedBy = null,
    ): static {
        return $this->state(fn (): array => [
            'status' => SyncConflict::STATUS_RESOLVED,
            'resolution' => $resolution,
            'reviewed_by_user_id' => $reviewedBy?->getKey() ?? User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Bind the conflict to an existing operation and copy its entity fields.
     */
    public function forOperation(NodeOperation $operation): static
    {
        return $this->state(fn (): array => [
            'operation_id' => $operation->getKey(),
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
        ]);
    }

    /**
     * A write queued on a device and refused because its module went inactive
     * (MOD-017; M19.17).
     *
     * No operation, because there is none and there must not be one: the write
     * never became a node-to-node operation. What identifies it is the key the
     * device queued it under.
     */
    public function moduleInactive(?string $originOperationUuid = null): static
    {
        return $this->state(fn (): array => [
            'operation_id' => null,
            'origin_operation_uuid' => $originOperationUuid ?? (string) Str::uuid(),
            'conflict_type' => SyncConflict::TYPE_MODULE_INACTIVE,
            'entity_type' => 'shift_assignment',
            'local_value_json' => ['command' => 'add-staff-to-shift'],
            'remote_value_json' => [
                'module' => 'scheduling',
                'entitled' => true,
                'enabled' => false,
                'active' => false,
            ],
            'reason' => 'add-staff-to-shift was queued on a device and reached this node after '
                .'Scheduling was switched off, so it was refused rather than applied.',
        ]);
    }

    /**
     * Seed a conflict for a specific entity type with a matching operation.
     */
    public function forEntity(string $entityType, ?string $entityId = null): static
    {
        $entityId ??= (string) Str::uuid();

        return $this->state(fn (): array => [
            'operation_id' => NodeOperation::factory()->conflicted()->state([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }
}
