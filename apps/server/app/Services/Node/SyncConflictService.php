<?php

namespace App\Services\Node;

use App\Models\NodeOperation;
use App\Models\SyncConflict;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records operations that could not be safely applied into the God-mode sync
 * conflict queue (technical spec 10.3; data/API 14.2, 7.5).
 *
 * Creating a conflict is the only write path this service owns. It inserts an
 * open {@see SyncConflict} and marks the related {@see NodeOperation}
 * `conflicted` in one transaction so a conflict never exists without a
 * conflicted operation, and a conflicted operation always has a queue row.
 * Resolution (accept on-site / accept central, with audit) belongs to
 * {@see SyncConflictResolver}. Electron health for severe conflicts is M12.10.
 *
 * Unresolved conflicts must not block unrelated sync (technical spec 10.3):
 * callers enqueue a conflict and continue applying other operations.
 */
class SyncConflictService
{
    /**
     * Record a sync conflict for a stored operation that could not be applied
     * safely, and mark that operation conflicted.
     *
     * @param  array<string, mixed>|null  $localValue
     * @param  array<string, mixed>|null  $remoteValue
     */
    public function record(
        NodeOperation $operation,
        string $reason,
        string $conflictType = SyncConflict::TYPE_STATE_MISMATCH,
        ?array $localValue = null,
        ?array $remoteValue = null,
    ): SyncConflict {
        if (! $operation->exists) {
            throw new RuntimeException(
                'Sync conflicts require a stored node operation; this operation is not recorded yet.',
            );
        }

        if ($operation->isApplied()) {
            throw new RuntimeException(
                'An applied node operation cannot be moved into the sync conflict queue.',
            );
        }

        return DB::transaction(function () use ($operation, $reason, $conflictType, $localValue, $remoteValue): SyncConflict {
            $operation->refresh();

            if ($operation->isApplied()) {
                throw new RuntimeException(
                    'An applied node operation cannot be moved into the sync conflict queue.',
                );
            }

            $conflict = SyncConflict::query()->create([
                'operation_id' => $operation->getKey(),
                'conflict_type' => $conflictType,
                'entity_type' => $operation->entity_type,
                'entity_id' => $operation->entity_id,
                'local_value_json' => $localValue,
                'remote_value_json' => $remoteValue,
                'reason' => $reason,
                'status' => SyncConflict::STATUS_OPEN,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'resolution' => null,
            ]);

            $operation->forceFill([
                'status' => NodeOperation::STATUS_CONFLICTED,
                'failure_reason' => $reason,
            ])->save();

            return $conflict;
        });
    }

    /**
     * Record a conflict from an applier signal, copying the exception's local
     * and remote snapshots onto the queue row.
     */
    public function recordFromException(
        NodeOperation $operation,
        SyncConflictException $exception,
    ): SyncConflict {
        return $this->record(
            operation: $operation,
            reason: $exception->getMessage(),
            conflictType: $exception->conflictType,
            localValue: $exception->localValue,
            remoteValue: $exception->remoteValue,
        );
    }
}
