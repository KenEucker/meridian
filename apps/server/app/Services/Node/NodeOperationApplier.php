<?php

namespace App\Services\Node;

use App\Models\NodeOperation;

/**
 * Applies one stored node operation to local entity state (technical spec
 * 10.1).
 *
 * Receivers store remote operations before applying them, so an applier only
 * ever runs against an operation that is already durably recorded. That
 * separation is what makes a failed application recoverable: the operation
 * survives, and {@see NodeOperationReceiver::apply()} can run the applier again
 * later.
 *
 * Appliers must be idempotent. The receiver will not re-apply an operation it
 * has already marked applied, but an application that failed partway, or one
 * whose row update did not commit, is retried against the same entity. Write
 * toward the state the operation describes rather than assuming the entity is
 * untouched.
 *
 * Throwing marks the operation `failed` with the exception message as its
 * `failure_reason` and leaves it available for retry. An applier should throw
 * rather than swallow, because a silent no-op is indistinguishable from a
 * successful application on the row.
 *
 * Each entity type's applier belongs to the task that owns that entity's sync
 * behavior. Conflict outcomes, which are a third result distinct from applied
 * and failed, arrive with the sync conflict queue (M12.8, M12.9).
 */
interface NodeOperationApplier
{
    /**
     * Whether this applier handles the given operation. Dispatch is by
     * `entity_type` and `operation_type` rather than by payload shape, because
     * those are the fields the signature covers.
     */
    public function supports(NodeOperation $operation): bool;

    /**
     * Apply the operation to local state.
     *
     * @throws \Throwable when the operation cannot be applied
     */
    public function apply(NodeOperation $operation): void;
}
