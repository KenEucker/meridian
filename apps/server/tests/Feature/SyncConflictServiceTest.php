<?php

namespace Tests\Feature;

use App\Models\NodeOperation;
use App\Models\SyncConflict;
use App\Services\Node\SyncConflictException;
use App\Services\Node\SyncConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Service coverage for recording God-mode sync conflicts (technical spec 10.3;
 * data/API 14.2).
 */
class SyncConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_an_open_conflict_and_marks_the_operation_conflicted(): void
    {
        $operation = NodeOperation::factory()->received()->create([
            'entity_type' => 'attendance_record',
            'entity_id' => (string) Str::uuid(),
            'payload_json' => ['status' => 'closed'],
        ]);

        $conflict = app(SyncConflictService::class)->record(
            operation: $operation,
            reason: 'Local attendance is still open.',
            conflictType: SyncConflict::TYPE_STATE_MISMATCH,
            localValue: ['status' => 'open'],
            remoteValue: ['status' => 'closed'],
        );

        $this->assertDatabaseHas('sync_conflicts', [
            'id' => $conflict->id,
            'operation_id' => $operation->id,
            'conflict_type' => SyncConflict::TYPE_STATE_MISMATCH,
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
            'reason' => 'Local attendance is still open.',
            'status' => SyncConflict::STATUS_OPEN,
            'resolution' => null,
            'reviewed_by_user_id' => null,
        ]);

        $this->assertSame(['status' => 'open'], $conflict->fresh()->local_value_json);
        $this->assertSame(['status' => 'closed'], $conflict->fresh()->remote_value_json);

        $operation->refresh();

        $this->assertSame(NodeOperation::STATUS_CONFLICTED, $operation->status);
        $this->assertSame('Local attendance is still open.', $operation->failure_reason);
        $this->assertNull($operation->applied_at);
        $this->assertTrue($operation->isConflicted());
    }

    public function test_record_from_exception_copies_local_and_remote_snapshots(): void
    {
        $operation = NodeOperation::factory()->received()->create();

        $exception = new SyncConflictException(
            reason: 'State mismatch on check-out.',
            conflictType: SyncConflict::TYPE_STATE_MISMATCH,
            localValue: ['checked_out_at' => null],
            remoteValue: ['checked_out_at' => '2026-07-27T18:00:00Z'],
        );

        $conflict = app(SyncConflictService::class)->recordFromException($operation, $exception);

        $this->assertSame('State mismatch on check-out.', $conflict->reason);
        $this->assertSame(['checked_out_at' => null], $conflict->local_value_json);
        $this->assertSame(['checked_out_at' => '2026-07-27T18:00:00Z'], $conflict->remote_value_json);
        $this->assertSame(NodeOperation::STATUS_CONFLICTED, $operation->fresh()->status);
    }

    public function test_record_rejects_an_unpersisted_operation(): void
    {
        $operation = NodeOperation::factory()->received()->make();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stored node operation');

        app(SyncConflictService::class)->record(
            operation: $operation,
            reason: 'Should not be recorded.',
        );
    }

    public function test_record_rejects_an_already_applied_operation(): void
    {
        $operation = NodeOperation::factory()->applied()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('applied node operation');

        app(SyncConflictService::class)->record(
            operation: $operation,
            reason: 'Should not be recorded.',
        );
    }
}
