<?php

namespace App\Services\Node;

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
 * Appliers see {@see SignedNodeOperation} rather than the stored row, and that
 * is deliberate. Signatures cover the normalized operation fields only, so
 * `payload_json` is unauthenticated and could have been changed by anything
 * between two nodes without breaking verification. Every applied change is
 * therefore derived from signed fields, which also means an operation's meaning
 * belongs in `operation_type` and the entity it names rather than in payload
 * data.
 *
 * Appliers must be idempotent. The receiver will not re-apply an operation it
 * has already marked applied, but an application that failed partway is retried
 * against the same entity. Write toward the state the operation describes
 * rather than assuming the entity is untouched.
 *
 * Throwing a generic exception marks the operation `failed` with the exception
 * message as its `failure_reason` and leaves it available for retry. An
 * applier should throw rather than swallow, because a silent no-op is
 * indistinguishable from a successful application on the row.
 *
 * Throwing {@see SyncConflictException} is the third outcome: the receiver
 * records an open sync conflict and marks the operation `conflicted` instead
 * of `failed`. Conflict resolution (accept on-site / accept central) is
 * M12.9.
 *
 * Each entity type's applier belongs to the task that owns that entity's sync
 * behavior.
 */
interface NodeOperationApplier
{
    /**
     * Whether this applier handles the given operation. Dispatch is by
     * `entity_type` and `operation_type`, which the signature covers.
     */
    public function supports(SignedNodeOperation $operation): bool;

    /**
     * Apply the operation to local state.
     *
     * @throws SyncConflictException when local and remote state disagree and
     *                               the operation belongs in the conflict queue
     * @throws \Throwable when the operation cannot be applied and should retry
     */
    public function apply(SignedNodeOperation $operation): void;
}
