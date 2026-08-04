<?php

namespace App\Models;

use Database\Factories\StaffProfileChangeRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-initiated profile change a reviewer decides (M18.20A; VOL-017
 * through VOL-026; data/API 10.4).
 *
 * Two kinds share the row: a handle change beyond the VOL-017 self-service
 * allowance, and a profile picture submission. A pending picture lives on this
 * row and never on `staff` (VOL-021); only `approved` rows count against the
 * handle allowance (VOL-018).
 */
class StaffProfileChangeRequest extends Model
{
    /** @use HasFactory<StaffProfileChangeRequestFactory> */
    use HasFactory, HasUuids;

    public const KIND_HANDLE = 'handle';

    public const KIND_PROFILE_PICTURE = 'profile_picture';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'organization_id',
        'requested_by_user_id',
        'kind',
        'status',
        'previous_handle',
        'requested_handle',
        'pending_picture_path',
        'pending_picture_mime_type',
        'pending_picture_size_bytes',
        'pending_picture_width',
        'pending_picture_height',
        'self_service',
        'decided_by_user_id',
        'decided_at',
        'decision_reason',
        'dismissed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'self_service' => 'boolean',
            'decided_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @param  Builder<StaffProfileChangeRequest>  $query
     * @return Builder<StaffProfileChangeRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
