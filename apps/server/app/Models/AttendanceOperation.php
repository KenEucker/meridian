<?php

namespace App\Models;

use Database\Factories\AttendanceOperationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AttendanceOperation extends Model
{
    public const TYPE_CHECK_IN = 'check_in';

    public const TYPE_CHECK_OUT = 'check_out';

    public const TYPE_MARK_NO_SHOW = 'mark_no_show';

    public const TYPE_CORRECT = 'correct';

    /** @use HasFactory<AttendanceOperationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'operation_uuid',
        'event_id',
        'department_id',
        'team_id',
        'shift_id',
        'shift_assignment_id',
        'staff_id',
        'operation_type',
        'device_created_at',
        'server_received_at',
        'created_by_user_id',
        'origin_device_id',
        'origin_node_id',
        'source_context',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_created_at' => 'datetime',
            'server_received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Attendance operations are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Attendance operations are append-only and cannot be deleted.');
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function shiftAssignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function originDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'origin_device_id');
    }

    public function originNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'origin_node_id');
    }
}
