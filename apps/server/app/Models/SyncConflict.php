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
 * local and remote values. Resolution chooses accept on-site or accept
 * central (M12.9); this model stores the queue and review fields so that
 * resolver can fill them later.
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
     * Reviewed with an accept-on-site or accept-central choice (M12.9).
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
     * @var list<string>
     */
    protected $fillable = [
        'operation_id',
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
