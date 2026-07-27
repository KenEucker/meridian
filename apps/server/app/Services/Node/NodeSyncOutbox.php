<?php

namespace App\Services\Node;

use App\Models\Node;
use App\Models\NodeOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The operations this node still owes a peer, and the record of what the peer
 * has confirmed (technical spec 10.1, 10.2; data/API 13.3).
 *
 * An operation created here starts `pending`. On-site queues operations in that
 * state while there is no internet and pushes them later (technical spec 10.2),
 * so "pending" is the queue and needs no separate table. It becomes `sent` only
 * when the peer has confirmed it holds the operation — not when it was put on
 * the wire — because a response lost in flight would otherwise silently drop an
 * operation nobody would ever resend. Redelivering an operation the peer
 * already holds is safe, since `uuid` is the idempotency key (technical spec
 * 10.1), so erring towards resending is the cheap mistake and erring towards
 * "assume delivered" is the expensive one.
 *
 * Only operations that originated on this node are ever offered. Operations
 * received from a peer are stored, applied, and left alone, which is what keeps
 * two nodes from bouncing the same operation back and forth. Alpha 1 has
 * exactly one central node and one active on-site node per event (technical
 * spec 10.1), so there is no relaying to a third node and delivery state fits
 * on the operation row itself; a topology with several peers would need
 * per-peer delivery records instead, because one `status` column cannot say
 * "delivered to A but not to B".
 */
class NodeSyncOutbox
{
    /**
     * Operations queued for a peer, oldest first.
     *
     * Origin creation order is the delivery order, because a receiving node
     * applies operations in the order it is handed them and some entities need
     * ordered sync — an incident and the field reports attached to it, for
     * instance (technical spec 10.3). `uuid` breaks ties so two operations
     * created in the same instant still have a stable order on both sides.
     *
     * @return Collection<int, NodeOperation>
     */
    public function pendingFor(Node $localNode, Node $peer, ?int $limit = null): Collection
    {
        return NodeOperation::query()
            ->where('origin_node_id', $localNode->getKey())
            ->where('status', NodeOperation::STATUS_PENDING)
            ->where(function ($query) use ($peer): void {
                // An unaddressed operation goes to whichever peer this node
                // syncs with; an addressed one goes only to the node it names.
                $query->whereNull('target_node_id')
                    ->orWhere('target_node_id', $peer->getKey());
            })
            ->orderBy('created_at')
            ->orderBy('uuid')
            ->limit($limit ?? self::batchSize())
            ->get();
    }

    /**
     * @param  iterable<NodeOperation>  $operations
     * @return list<NodeOperationEnvelope>
     */
    public function envelopesFor(iterable $operations): array
    {
        $envelopes = [];

        foreach ($operations as $operation) {
            $envelopes[] = NodeOperationEnvelope::fromOperation($operation);
        }

        return $envelopes;
    }

    /**
     * Mark operations the peer confirmed it now holds.
     *
     * Only this node's own pending operations are touched. An acknowledgement
     * naming an operation this node did not originate, or one that is no longer
     * pending, changes nothing: a peer cannot rewrite delivery state for
     * operations that are not its to confirm.
     *
     * @param  list<string>  $uuids
     * @return int the number of operations that moved to `sent`
     */
    public function markSent(Node $localNode, array $uuids): int
    {
        if ($uuids === []) {
            return 0;
        }

        $marked = 0;

        // Marked one row at a time rather than by mass update so the model's
        // append-only guard stays in force: a delivery mark may only touch the
        // lifecycle columns (data/API 13.3).
        foreach ($this->pendingOwn($localNode, $uuids)->get() as $operation) {
            $operation->forceFill([
                'status' => NodeOperation::STATUS_SENT,
                'sent_at' => now(),
            ])->save();

            $marked++;
        }

        return $marked;
    }

    /**
     * Park operations the peer refused.
     *
     * A refused operation will be refused again for the same reason, so leaving
     * it pending would offer it on every run forever. It is marked `failed`
     * with the peer's reason and an incremented `retry_count` instead, which
     * takes it out of the outbox while keeping it in the log, readable, and
     * available for a deliberate retry once the reason is fixed (technical spec
     * 9.2, 10.4).
     *
     * @param  list<NodeSyncOperationResult>  $refusals
     * @return int the number of operations that moved to `failed`
     */
    public function markRefused(Node $localNode, array $refusals): int
    {
        $marked = 0;

        foreach ($refusals as $refusal) {
            $operation = $this->pendingOwn($localNode, [$refusal->uuid])->first();

            if (! $operation instanceof NodeOperation) {
                continue;
            }

            $operation->forceFill([
                'status' => NodeOperation::STATUS_FAILED,
                'failure_reason' => $refusal->failureReason(),
                'retry_count' => (int) $operation->retry_count + 1,
            ])->save();

            $marked++;
        }

        return $marked;
    }

    /**
     * How many operations one exchange carries. Batching bounds a single
     * request; a backlog drains over repeated exchanges within one sync run.
     */
    public static function batchSize(): int
    {
        return max(1, (int) config('meridian.node.sync.batch_size', 100));
    }

    /**
     * @param  list<string>  $uuids
     * @return Builder<NodeOperation>
     */
    private function pendingOwn(Node $localNode, array $uuids): Builder
    {
        return NodeOperation::query()
            ->where('origin_node_id', $localNode->getKey())
            ->where('status', NodeOperation::STATUS_PENDING)
            ->whereIn('uuid', $uuids);
    }
}
