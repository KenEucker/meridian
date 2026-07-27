<?php

namespace App\Services\Node;

use App\Models\NodeOperation;

/**
 * What happened when this node received one remote operation (technical spec
 * 10.1).
 *
 * Receiving the same operation more than once is safe, which means "stored" and
 * "already had it" are both successful outcomes and a caller that cannot tell
 * them apart cannot report honestly on a sync run. `stored` distinguishes them.
 *
 * The operation's own row carries the rest: `applied_at` says whether it has
 * been applied, and `status`/`failure_reason`/`retry_count` describe a failed
 * application that is still recoverable.
 */
final class NodeOperationReceipt
{
    public function __construct(
        public readonly NodeOperation $operation,
        /** Whether this receipt is what wrote the operation to the log. */
        public readonly bool $stored,
    ) {}

    /**
     * A redelivery of an operation this node already held.
     */
    public function isDuplicate(): bool
    {
        return ! $this->stored;
    }

    public function wasApplied(): bool
    {
        return $this->operation->isApplied();
    }

    public function hasFailed(): bool
    {
        return $this->operation->status === NodeOperation::STATUS_FAILED;
    }

    public function failureReason(): ?string
    {
        return $this->operation->failure_reason;
    }
}
