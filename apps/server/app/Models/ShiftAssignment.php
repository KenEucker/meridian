<?php

namespace App\Models;

use Database\Factories\ShiftAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftAssignment extends Model
{
    public const STATUS_SIGNED_UP = 'signed_up';

    public const STATUS_ASSIGNED = 'assigned';

    /** @use HasFactory<ShiftAssignmentFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'staff_id',
        'assigned_by_user_id',
        'assignment_status',
        'removed_at',
        /*
         * The device-generated key an unscheduled addition was queued under
         * (M18.54; data/API 5.3). Null on every assignment made any other way —
         * a signup, a lead's roster edit, an import — because those are not
         * commands a device held.
         */
        'unscheduled_operation_uuid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_SIGNED_UP,
            self::STATUS_ASSIGNED,
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function attendanceOperations(): HasMany
    {
        return $this->hasMany(AttendanceOperation::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * @param  Builder<ShiftAssignment>  $query
     * @return Builder<ShiftAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isSelfSignup(): bool
    {
        return $this->assignment_status === self::STATUS_SIGNED_UP
            && $this->assigned_by_user_id === null;
    }
}
