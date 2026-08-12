<?php

namespace App\Models;

use Database\Factories\SyncConflictFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

/**
 * A sync conflict awaiting God-mode review (technical spec 10.3; data/API
 * 14.2).
 *
 * Conflicts are operations that could not be safely applied. They are visible
 * only in God Mode / Orchid for Alpha 1, grouped by entity type, and show both
 * local and remote values. Resolution chooses accept on-site or accept central
 * and is audited; `SyncConflictResolver` is the only write path for the review
 * columns.
 *
 * Status and resolution values are Alpha 1 conventions: section 14.2 names the
 * columns but not the enumerations, while technical spec 10.3 / data/API 7.5
 * name the two resolve choices.
 */
class SyncConflict extends Model
{
    /** @use HasFactory<SyncConflictFactory> */
    use AsSource;
    use Filterable;
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Not yet reviewed. Unresolved conflicts must not block unrelated sync
     * (technical spec 10.3).
     */
    public const STATUS_OPEN = 'open';

    /**
     * Reviewed with an accept-on-site or accept-central choice, and audited
     * (technical spec 10.3).
     */
    public const STATUS_RESOLVED = 'resolved';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_RESOLVED,
    ];

    /**
     * Accept the on-site node's version (technical spec 10.3).
     */
    public const RESOLUTION_ACCEPT_ONSITE = 'accept_onsite';

    /**
     * Accept the central node's version (technical spec 10.3).
     */
    public const RESOLUTION_ACCEPT_CENTRAL = 'accept_central';

    /**
     * @var list<string>
     */
    public const RESOLUTIONS = [
        self::RESOLUTION_ACCEPT_ONSITE,
        self::RESOLUTION_ACCEPT_CENTRAL,
    ];

    /**
     * Local and remote entity state disagree and the operation cannot be
     * applied safely. Entity appliers name more specific types as they land;
     * this is the default Alpha 1 type for a state disagreement.
     */
    public const TYPE_STATE_MISMATCH = 'state_mismatch';

    /**
     * A write queued on a device reached a module the organization no longer
     * runs (MOD-017; technical spec 15A.8).
     *
     * The one conflict type with no node operation behind it. There are not two
     * versions of a record to choose between here: there is a write, and an
     * organization that has said it does not run the capability the write
     * belongs to. {@see \App\Services\Node\SyncConflictResolver} is where that
     * narrows the reviewer's two choices to one.
     */
    public const TYPE_MODULE_INACTIVE = 'module_inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'operation_id',
        'origin_operation_uuid',
        'conflict_type',
        'entity_type',
        'entity_id',
        'local_value_json',
        'remote_value_json',
        'reason',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'resolution',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'entity_type' => Like::class,
        'conflict_type' => Like::class,
        'status' => Where::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'entity_type',
        'conflict_type',
        'status',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_value_json' => 'array',
            'remote_value_json' => 'array',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(NodeOperation::class, 'operation_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @param  Builder<SyncConflict>  $query
     * @return Builder<SyncConflict>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * @param  Builder<SyncConflict>  $query
     * @return Builder<SyncConflict>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $this->scopeOpen($query);
    }

    /**
     * @param  Builder<SyncConflict>  $query
     * @return Builder<SyncConflict>
     */
    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Whether this conflict is a refused device write rather than a node-to-node
     * disagreement (MOD-017).
     *
     * Asked of the type rather than of `operation_id` being null, because the
     * type is what the row *means* and the missing operation is a consequence of
     * it. A row that lost its operation some other way is a defect, not a device
     * write, and must not start resolving like one.
     */
    public function isDeviceWrite(): bool
    {
        return $this->conflict_type === self::TYPE_MODULE_INACTIVE;
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => __('Open'),
            self::STATUS_RESOLVED => __('Resolved'),
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /**
     * @return array<string, string>
     */
    public static function resolutionLabels(): array
    {
        return [
            self::RESOLUTION_ACCEPT_ONSITE => __('Accept on-site'),
            self::RESOLUTION_ACCEPT_CENTRAL => __('Accept central'),
        ];
    }

    public function resolutionLabel(): string
    {
        if ($this->resolution === null) {
            return __('Not resolved');
        }

        return self::resolutionLabels()[$this->resolution] ?? $this->resolution;
    }
}
