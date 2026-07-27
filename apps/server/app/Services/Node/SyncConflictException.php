<?php

namespace App\Services\Node;

use App\Models\NodeOperation;
use App\Models\SyncConflict;
use Throwable;

/**
 * Signals that a stored node operation cannot be safely applied and belongs
 * in the God-mode sync conflict queue (technical spec 10.3; data/API 14.2).
 *
 * Appliers throw this instead of a generic failure when local and remote state
 * disagree. {@see NodeOperationReceiver::apply()} catches it, records a
 * {@see SyncConflict}, and marks the operation `conflicted` without treating
 * the conflict as a retriable failure. {@see SyncConflictResolver} then carries
 * out the reviewer's accept on-site / accept central choice, and an applier
 * must not raise this exception for an operation that carries
 * {@see SignedNodeOperation::$resolvedConflict}.
 */
class SyncConflictException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $localValue
     * @param  array<string, mixed>|null  $remoteValue
     */
    public function __construct(
        string $reason,
        public readonly string $conflictType = SyncConflict::TYPE_STATE_MISMATCH,
        public readonly ?array $localValue = null,
        public readonly ?array $remoteValue = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, previous: $previous);
    }
}
