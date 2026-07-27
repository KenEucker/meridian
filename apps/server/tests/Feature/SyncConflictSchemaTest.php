<?php

namespace Tests\Feature;

use App\Models\NodeOperation;
use App\Models\SyncConflict;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schema coverage for the God-mode sync conflict queue (technical spec 10.3;
 * data/API 14.2).
 */
class SyncConflictSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_conflicts_table_has_documented_columns(): void
    {
        $expected = [
            'id',
            'operation_id',
            'conflict_type',
            'entity_type',
            'entity_id',
            'local_value_json',
            'remote_value_json',
            'reason',
            'status',
            'reviewed_by_user_id',
            'reviewed_at',
            'resolution',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('sync_conflicts', $column),
                "sync_conflicts should have a {$column} column.",
            );
        }
    }

    public function test_sync_conflicts_use_uuid_primary_keys(): void
    {
        $conflict = SyncConflict::factory()->create();

        $this->assertFalse($conflict->getIncrementing());
        $this->assertSame('string', $conflict->getKeyType());
        $this->assertTrue(Str::isUuid($conflict->getKey()));
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $conflict = SyncConflict::factory()->create([
            'local_value_json' => ['status' => 'open'],
            'remote_value_json' => ['status' => 'closed'],
        ]);

        $conflict->refresh();

        $this->assertSame(['status' => 'open'], $conflict->local_value_json);
        $this->assertSame(['status' => 'closed'], $conflict->remote_value_json);
        $this->assertNotNull($conflict->created_at);
        $this->assertNotNull($conflict->updated_at);
    }

    public function test_a_conflict_belongs_to_its_operation_and_copies_entity_fields(): void
    {
        $operation = NodeOperation::factory()->conflicted()->create([
            'entity_type' => 'attendance_record',
            'entity_id' => (string) Str::uuid(),
        ]);

        $conflict = SyncConflict::factory()->forOperation($operation)->create();

        $this->assertTrue($conflict->operation->is($operation));
        $this->assertSame($operation->entity_type, $conflict->entity_type);
        $this->assertSame($operation->entity_id, $conflict->entity_id);
        $this->assertTrue($operation->syncConflicts->contains($conflict));
    }

    public function test_open_scope_excludes_resolved_conflicts(): void
    {
        $open = SyncConflict::factory()->open()->create();
        SyncConflict::factory()->resolved()->create();

        $this->assertSame(
            [$open->id],
            SyncConflict::query()->open()->pluck('id')->all(),
        );
    }

    public function test_resolved_factory_fills_review_metadata(): void
    {
        $reviewer = User::factory()->create();
        $conflict = SyncConflict::factory()
            ->resolved(SyncConflict::RESOLUTION_ACCEPT_CENTRAL, $reviewer)
            ->create();

        $this->assertTrue($conflict->isResolved());
        $this->assertSame(SyncConflict::RESOLUTION_ACCEPT_CENTRAL, $conflict->resolution);
        $this->assertSame($reviewer->id, $conflict->reviewed_by_user_id);
        $this->assertNotNull($conflict->reviewed_at);
        $this->assertSame(__('Accept central'), $conflict->resolutionLabel());
    }
}
