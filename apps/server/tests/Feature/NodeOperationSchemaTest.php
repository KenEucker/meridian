<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Append-only node operation storage (technical spec 10.1, 10.4; data/API
 * 13.3).
 */
class NodeOperationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_node_operations_table_has_documented_columns(): void
    {
        $expected = [
            'id',
            'uuid',
            'origin_node_id',
            'target_node_id',
            'actor_user_id',
            'actor_device_id',
            'operation_type',
            'entity_type',
            'entity_id',
            'event_id',
            'created_at',
            'sent_at',
            'received_at',
            'applied_at',
            'status',
            'signature',
            'hash',
            'payload_json',
            'failure_reason',
            'retry_count',
        ];

        $this->assertTrue(Schema::hasTable('node_operations'));

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('node_operations', $column),
                "node_operations should have a {$column} column.",
            );
        }
    }

    public function test_node_operations_have_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('node_operations', 'updated_at'));
    }

    public function test_node_operations_use_uuid_primary_keys(): void
    {
        $operation = NodeOperation::factory()->create();

        $this->assertFalse($operation->getIncrementing());
        $this->assertSame('string', $operation->getKeyType());
        $this->assertTrue(Str::isUuid($operation->getKey()));
    }

    public function test_operation_uuid_is_unique_so_repeated_receipt_is_safe(): void
    {
        $uuid = (string) Str::uuid();

        NodeOperation::factory()->create(['uuid' => $uuid]);

        $this->expectException(QueryException::class);

        NodeOperation::factory()->create(['uuid' => $uuid]);
    }

    public function test_operation_records_origin_actor_entity_and_event_scope(): void
    {
        $origin = Node::factory()->onsite()->create();
        $target = Node::factory()->remote()->central()->create();
        $actor = User::factory()->create();
        $device = Device::factory()->create();
        $event = Event::factory()->create();
        $entityId = (string) Str::uuid();

        $operation = NodeOperation::factory()->create([
            'origin_node_id' => $origin->id,
            'target_node_id' => $target->id,
            'actor_user_id' => $actor->id,
            'actor_device_id' => $device->id,
            'entity_type' => 'incident',
            'entity_id' => $entityId,
            'event_id' => $event->id,
        ]);

        $operation->refresh();

        $this->assertTrue($operation->originNode->is($origin));
        $this->assertTrue($operation->targetNode->is($target));
        $this->assertTrue($operation->actorUser->is($actor));
        $this->assertTrue($operation->actorDevice->is($device));
        $this->assertTrue($operation->event->is($event));
        $this->assertTrue($origin->originatedOperations->contains($operation));
        $this->assertTrue($target->targetedOperations->contains($operation));
        $this->assertSame(
            [$operation->id],
            NodeOperation::query()->forEntity('incident', $entityId)->pluck('id')->all(),
        );
    }

    public function test_target_node_event_and_device_are_optional(): void
    {
        $operation = NodeOperation::factory()->create([
            'target_node_id' => null,
            'actor_device_id' => null,
            'event_id' => null,
            'payload_json' => null,
        ]);

        $operation->refresh();

        $this->assertNull($operation->target_node_id);
        $this->assertNull($operation->actor_device_id);
        $this->assertNull($operation->event_id);
        $this->assertNull($operation->payload_json);
    }

    public function test_payload_json_is_cast_to_an_array(): void
    {
        $operation = NodeOperation::factory()->create([
            'payload_json' => ['status' => 'closed', 'closed_by' => 'ingrid'],
        ]);

        $operation->refresh();

        $this->assertSame(['status' => 'closed', 'closed_by' => 'ingrid'], $operation->payload_json);
    }

    public function test_operation_keeps_the_origin_creation_time_rather_than_local_insert_time(): void
    {
        $createdAt = now()->subHours(6)->startOfSecond();

        $operation = NodeOperation::factory()->create(['created_at' => $createdAt]);

        $operation->refresh();

        $this->assertTrue($operation->created_at->equalTo($createdAt));
    }

    public function test_lifecycle_timestamps_and_status_progress_through_the_documented_states(): void
    {
        $operation = NodeOperation::factory()->create();

        $this->assertSame(NodeOperation::STATUS_PENDING, $operation->status);
        $this->assertNull($operation->sent_at);
        $this->assertNull($operation->received_at);
        $this->assertNull($operation->applied_at);
        $this->assertFalse($operation->isApplied());
        $this->assertSame(0, $operation->retry_count);

        $operation->update([
            'status' => NodeOperation::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $operation->update([
            'status' => NodeOperation::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        $this->assertSame([$operation->id], NodeOperation::query()->unapplied()->pluck('id')->all());

        $operation->update([
            'status' => NodeOperation::STATUS_APPLIED,
            'applied_at' => now(),
        ]);

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_APPLIED, $operation->status);
        $this->assertTrue($operation->isApplied());
        $this->assertSame([], NodeOperation::query()->unapplied()->pluck('id')->all());
    }

    public function test_failed_delivery_records_a_reason_and_retry_count(): void
    {
        $operation = NodeOperation::factory()->failed('Central refused the event-scoped edit.')->create();

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_FAILED, $operation->status);
        $this->assertSame('Central refused the event-scoped edit.', $operation->failure_reason);
        $this->assertSame(1, $operation->retry_count);
        $this->assertNull($operation->applied_at);
    }

    public function test_documented_statuses_are_available(): void
    {
        $this->assertSame([
            'pending',
            'sent',
            'received',
            'applied',
            'failed',
            'conflicted',
        ], NodeOperation::STATUSES);
    }

    public function test_operation_content_cannot_be_rewritten_after_it_is_recorded(): void
    {
        $operation = NodeOperation::factory()->create();

        $this->expectException(RuntimeException::class);

        $operation->update(['payload_json' => ['tampered' => true]]);
    }

    public function test_signature_and_hash_cannot_be_rewritten_after_it_is_recorded(): void
    {
        $operation = NodeOperation::factory()->create();

        $this->expectException(RuntimeException::class);

        $operation->update(['signature' => 'forged', 'hash' => 'forged']);
    }

    public function test_normalized_fields_cannot_be_rewritten_after_it_is_recorded(): void
    {
        $operation = NodeOperation::factory()->create();

        $this->expectException(RuntimeException::class);

        $operation->update(['entity_id' => (string) Str::uuid()]);
    }

    public function test_node_operations_cannot_be_deleted(): void
    {
        $operation = NodeOperation::factory()->create();

        $this->expectException(RuntimeException::class);

        $operation->delete();
    }

    public function test_normalized_attributes_projection_matches_the_specified_field_list(): void
    {
        $createdAt = now()->subHour()->startOfSecond();

        $operation = NodeOperation::factory()->create([
            'target_node_id' => null,
            'actor_device_id' => null,
            'event_id' => null,
            'created_at' => $createdAt,
        ]);

        $normalized = $operation->normalizedAttributes();

        $this->assertSame(NodeOperation::NORMALIZED_ATTRIBUTES, array_keys($normalized));
        $this->assertSame($operation->uuid, $normalized['uuid']);
        $this->assertSame($operation->origin_node_id, $normalized['origin_node_id']);
        $this->assertNull($normalized['target_node_id']);
        $this->assertNull($normalized['actor_device_id']);
        $this->assertNull($normalized['event_id']);
        $this->assertSame($createdAt->utc()->toIso8601String(), $normalized['created_at']);
        $this->assertStringEndsWith('+00:00', $normalized['created_at']);
    }

    public function test_normalized_attributes_exclude_payload_signature_and_lifecycle_fields(): void
    {
        $operation = NodeOperation::factory()->applied()->create([
            'payload_json' => ['status' => 'closed'],
        ]);

        $normalized = $operation->normalizedAttributes();

        foreach (['payload_json', 'signature', 'hash', ...NodeOperation::LIFECYCLE_ATTRIBUTES] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $normalized);
        }
    }
}
