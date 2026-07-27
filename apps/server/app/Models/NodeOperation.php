<?php

namespace App\Models;

use Database\Factories\NodeOperationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An append-only node-to-node sync operation (technical spec 10.1, 10.4;
 * data/API 13.3).
 *
 * Append-only here means two things. Operations are never deleted, and the
 * content of an operation — the normalized fields the signature covers, plus
 * the payload, signature, and hash — is never rewritten after insert. The
 * delivery lifecycle columns listed in technical spec 10.4 (`sent_at`,
 * `received_at`, `applied_at`, `status`, `failure_reason`, `retry_count`) do
 * change as an operation is sent, received, applied, or retried, so those are
 * the only attributes an update may touch.
 *
 * `uuid` is the idempotency key. Receiving the same operation more than once
 * must be safe (technical spec 10.1), so the column is unique and receivers
 * resolve an operation by `uuid`, not by local primary key.
 *
 * This slice is the storage shape only. Signing and verification (M12.3) and
 * the receive/store/apply path (M12.4) are separate tasks; nothing here
 * produces or checks a signature.
 */
class NodeOperation extends Model
{
    /** @use HasFactory<NodeOperationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Node operations carry the origin node's creation time and no local
     * update timestamp (data/API 13.3).
     */
    public const UPDATED_AT = null;

    /**
     * Created at the origin node and not yet sent to a peer. On-site queues
     * operations in this state while there is no internet (technical spec
     * 10.2).
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    /**
     * Stored by the receiver but not yet applied. Receivers store remote
     * operations before applying them (technical spec 10.1).
     */
    public const STATUS_RECEIVED = 'received';

    public const STATUS_APPLIED = 'applied';

    /**
     * Delivery or application failed; `failure_reason` and `retry_count`
     * describe the failure (technical spec 10.4).
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Could not be safely applied and belongs in the sync conflict queue
     * (technical spec 10.3; data/API 14.2).
     */
    public const STATUS_CONFLICTED = 'conflicted';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_RECEIVED,
        self::STATUS_APPLIED,
        self::STATUS_FAILED,
        self::STATUS_CONFLICTED,
    ];

    /**
     * The normalized operation fields from technical spec 10.4, in the order
     * the specification lists them. Operation signatures cover these fields,
     * so M12.3 signs and verifies exactly this projection rather than the
     * whole row.
     *
     * @var list<string>
     */
    public const NORMALIZED_ATTRIBUTES = [
        'uuid',
        'origin_node_id',
        'target_node_id',
        'actor_user_id',
        'actor_device_id',
        'operation_type',
        'entity_type',
        'entity_id',
        'event_id',
        'created_at',
    ];

    /**
     * Delivery lifecycle attributes. These are the only attributes an existing
     * operation may change, because an operation's progress through send,
     * receipt, application, and retry is tracked on the same row (technical
     * spec 10.4).
     *
     * @var list<string>
     */
    public const LIFECYCLE_ATTRIBUTES = [
        'sent_at',
        'received_at',
        'applied_at',
        'status',
        'failure_reason',
        'retry_count',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'origin_node_id',
        'target_node_id',
        'actor_user_id',
        'actor_device_id',
        'operation_type',
        'entity_type',
        'entity_id',
        'event_id',
        'created_at',
        'sent_at',
        'received_at',
        'applied_at',
        'status',
        'signature',
        'hash',
        'payload_json',
        'failure_reason',
        'retry_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'applied_at' => 'datetime',
            'payload_json' => 'array',
            'retry_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (NodeOperation $operation): void {
            $changed = array_keys($operation->getDirty());
            $content = array_values(array_diff($changed, self::LIFECYCLE_ATTRIBUTES));

            if ($content !== []) {
                throw new RuntimeException(sprintf(
                    'Node operations are append-only; %s cannot be changed after the operation is recorded.',
                    implode(', ', $content),
                ));
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Node operations are append-only and cannot be deleted.');
        });
    }

    public function originNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'origin_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'target_node_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'actor_device_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The normalized operation fields a signature covers (technical spec 10.4),
     * keyed in specification order so the projection is stable regardless of
     * attribute assignment order. `created_at` is rendered as ISO-8601 UTC so
     * the projection does not shift with a node's configured timezone.
     *
     * @return array<string, string|null>
     */
    public function normalizedAttributes(): array
    {
        $normalized = [];

        foreach (self::NORMALIZED_ATTRIBUTES as $attribute) {
            $value = $this->getAttribute($attribute);

            $normalized[$attribute] = match (true) {
                $value === null => null,
                $attribute === 'created_at' => $this->created_at?->utc()->toIso8601String(),
                default => (string) $value,
            };
        }

        return $normalized;
    }

    /**
     * Operations that have not yet been applied on this node.
     *
     * @param  Builder<NodeOperation>  $query
     * @return Builder<NodeOperation>
     */
    public function scopeUnapplied(Builder $query): Builder
    {
        return $query->whereNull('applied_at');
    }

    /**
     * @param  Builder<NodeOperation>  $query
     * @return Builder<NodeOperation>
     */
    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }
}
