<?php

namespace App\Services\Node;

use App\Models\NodeOperation;

/**
 * The set of {@see NodeOperationApplier} implementations this node can apply
 * operations with (technical spec 10.1).
 *
 * Sync is operation-based rather than table-replication-based, so the receive
 * path cannot know how to write every synced entity. It knows how to store an
 * operation, verify it, and hand it to whichever applier claims it; each
 * entity's applier is registered by the task that owns that entity's sync
 * behavior.
 *
 * An operation no applier claims is not a refusal. It was authentic enough to
 * store, so it stays in the log and is marked `failed` with a readable reason,
 * which means a node that receives an operation for an entity type it does not
 * yet understand keeps it and can apply it after an upgrade rather than losing
 * it.
 *
 * Registration order is resolution order: the first applier that claims an
 * operation handles it.
 */
class NodeOperationApplierRegistry
{
    /**
     * @var list<NodeOperationApplier>
     */
    private array $appliers = [];

    public function register(NodeOperationApplier $applier): static
    {
        $this->appliers[] = $applier;

        return $this;
    }

    public function applierFor(NodeOperation $operation): ?NodeOperationApplier
    {
        foreach ($this->appliers as $applier) {
            if ($applier->supports($operation)) {
                return $applier;
            }
        }

        return null;
    }

    /**
     * @return list<NodeOperationApplier>
     */
    public function all(): array
    {
        return $this->appliers;
    }
}
