<?php

namespace App\Models;

use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_CHECKED_IN = 'checked_in';

    public const STATE_CHECKED_OUT = 'checked_out';

    public const STATE_NO_SHOW = 'no_show';

    public const STATE_EXCUSED = 'excused';

    public const STATE_CORRECTED = 'corrected';

    /** @use HasFactory<AttendanceRecordFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'department_id',
        'shift_id',
        'shift_assignment_id',
        'staff_id',
        'current_state',
        'checked_in_at',
        'checked_out_at',
        'no_show_at',
        'corrected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'no_show_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_SCHEDULED,
            self::STATE_CHECKED_IN,
            self::STATE_CHECKED_OUT,
            self::STATE_NO_SHOW,
            self::STATE_EXCUSED,
            self::STATE_CORRECTED,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
}
