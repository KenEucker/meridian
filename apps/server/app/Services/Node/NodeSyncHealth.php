<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What the node operation log says about the health of node-to-node sync
 * (technical spec 10.1, 10.2, 25.3).
 *
 * Sync health is derived rather than stored. The operation log already records
 * everything a reader needs — what is queued, what the peer confirmed, what
 * arrived, and what failed — so keeping a separate "last run" record would add a
 * second source of truth that can disagree with the first. A run that crashed
 * before writing its own status would look healthier than one that finished and
 * reported a problem, which is exactly backwards.
 *
 * The two directions are counted apart because they fail for different reasons
 * and need different responses. Operations queued here and not yet delivered
 * mean this node cannot reach its peer; operations that arrived and could not be
 * applied mean the peer is reachable and something local is wrong. A single
 * "sync is broken" number would hide which.
 *
 * Queued work is not a fault. On-site queues operations whenever the internet is
 * gone and pushes them later (technical spec 10.2), so a backlog is the system
 * working as designed and is reported without alarm. Failures and refused
 * exchanges are faults, and they set the attention state.
 *
 * This is the God-mode read model. Electron health (M12.10) surfaces the same
 * signals in the on-site command centre. The sync conflict queue (M12.8) owns
 * disagreements between local and remote state, which are a different thing
 * from the delivery and application failures counted here; conflict resolution
 * is M12.9.
 */
class NodeSyncHealth
{
    /** No node is configured on this install yet. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /** Nothing has been synced yet. */
    public const STATUS_IDLE = 'idle';

    /** Operations are queued and waiting for the peer. */
    public const STATUS_QUEUED = 'queued';

    /** Operations moved and nothing failed. */
    public const STATUS_HEALTHY = 'healthy';

    /** Something failed or an exchange was refused. */
    public const STATUS_ATTENTION = 'attention';

    /** How many recent failures and refusals to show. */
    private const RECENT_LIMIT = 5;

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     queued: int,
     *     delivered: int,
     *     undelivered: int,
     *     received: int,
     *     applied: int,
     *     unapplied: int,
     *     oldest_queued_at: ?string,
     *     last_sent_at: ?string,
     *     last_received_at: ?string,
     *     failures: list<array{uuid: string, direction: string, operation: string, reason: ?string, retry_count: int, at: ?string}>,
     *     refusals: list<array{reason_code: ?string, reason: ?string, at: ?string}>,
     * }
     */
    public function describe(?Node $node): array
    {
        if (! $node instanceof Node) {
            return $this->empty(self::STATUS_NOT_CONFIGURED);
        }

        $mine = $this->countsFor($node, own: true);
        $theirs = $this->countsFor($node, own: false);

        $failures = $this->recentFailures($node);
        $refusals = $this->recentRefusals();

        $queued = $mine[NodeOperation::STATUS_PENDING] ?? 0;
        $undelivered = $mine[NodeOperation::STATUS_FAILED] ?? 0;
        $unapplied = $theirs[NodeOperation::STATUS_FAILED] ?? 0;

        return [
            'status' => $status = $this->resolveStatus(
                moved: array_sum($mine) + array_sum($theirs),
                queued: $queued,
                failed: $undelivered + $unapplied,
                refusals: count($refusals),
            ),
            'status_label' => self::statusLabel($status),
            'queued' => $queued,
            'delivered' => $mine[NodeOperation::STATUS_SENT] ?? 0,
            'undelivered' => $undelivered,
            'received' => $theirs[NodeOperation::STATUS_RECEIVED] ?? 0,
            'applied' => $theirs[NodeOperation::STATUS_APPLIED] ?? 0,
            'unapplied' => $unapplied,
            'oldest_queued_at' => $this->timestamp(
                $this->own($node)->where('status', NodeOperation::STATUS_PENDING)->min('created_at'),
            ),
            'last_sent_at' => $this->timestamp($this->own($node)->max('sent_at')),
            'last_received_at' => $this->timestamp($this->fromPeers($node)->max('received_at')),
            'failures' => $failures,
            'refusals' => $refusals,
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_NOT_CONFIGURED => 'No node configured',
            self::STATUS_IDLE => 'Nothing synced yet',
            self::STATUS_QUEUED => 'Operations queued for the peer',
            self::STATUS_HEALTHY => 'Syncing',
            self::STATUS_ATTENTION => 'Needs attention',
            default => $status,
        };
    }

    /**
     * Queued work is a state, not a fault: a backlog is what an on-site node is
     * supposed to build while the internet is gone. Only failures and refused
     * exchanges ask for a human.
     */
    private function resolveStatus(int $moved, int $queued, int $failed, int $refusals): string
    {
        if ($failed > 0 || $refusals > 0) {
            return self::STATUS_ATTENTION;
        }

        if ($queued > 0) {
            return self::STATUS_QUEUED;
        }

        return $moved > 0 ? self::STATUS_HEALTHY : self::STATUS_IDLE;
    }

    /**
     * @return array<string, int>
     */
    private function countsFor(Node $node, bool $own): array
    {
        $query = $own ? $this->own($node) : $this->fromPeers($node);

        return $query
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @return list<array{uuid: string, direction: string, operation: string, reason: ?string, retry_count: int, at: ?string}>
     */
    private function recentFailures(Node $node): array
    {
        return NodeOperation::query()
            ->where('status', NodeOperation::STATUS_FAILED)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (NodeOperation $operation): array => [
                'uuid' => (string) $operation->uuid,
                'direction' => (string) $operation->origin_node_id === (string) $node->getKey()
                    ? 'outbound'
                    : 'inbound',
                'operation' => $operation->operation_type.' / '.$operation->entity_type,
                'reason' => $operation->failure_reason,
                'retry_count' => (int) $operation->retry_count,
                'at' => $this->timestamp($operation->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Exchanges this node refused outright. These never reach the operation log,
     * so the audit trail is the only record of them (data/API 13.6, 14.1).
     *
     * @return list<array{reason_code: ?string, reason: ?string, at: ?string}>
     */
    private function recentRefusals(): array
    {
        return AuditEvent::query()
            ->whereIn('action', [NodeSyncService::AUDIT_REFUSED, NodeOperationReceiver::AUDIT_REJECTED])
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (AuditEvent $audit): array => [
                'reason_code' => is_array($audit->after_json)
                    ? ($audit->after_json['reason_code'] ?? null)
                    : null,
                'reason' => $audit->reason,
                'at' => $this->timestamp($audit->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<NodeOperation>
     */
    private function own(Node $node)
    {
        return NodeOperation::query()->where('origin_node_id', $node->getKey());
    }

    /**
     * @return Builder<NodeOperation>
     */
    private function fromPeers(Node $node)
    {
        return NodeOperation::query()->whereNot('origin_node_id', $node->getKey());
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon
            ? $value->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $status): array
    {
        return [
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'queued' => 0,
            'delivered' => 0,
            'undelivered' => 0,
            'received' => 0,
            'applied' => 0,
            'unapplied' => 0,
            'oldest_queued_at' => null,
            'last_sent_at' => null,
            'last_received_at' => null,
            'failures' => [],
            'refusals' => [],
        ];
    }
}
