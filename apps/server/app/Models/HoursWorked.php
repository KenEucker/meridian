<?php

namespace App\Models;

use Database\Factories\HoursWorkedFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoursWorked extends Model
{
    public const STATUS_RECORDED = 'recorded';

    /** @use HasFactory<HoursWorkedFactory> */
    use HasFactory, HasUuids;

    protected $table = 'hours_worked';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'department_id',
        'shift_id',
        'staff_id',
        'attendance_record_id',
        'actual_started_at',
        'actual_ended_at',
        'minutes_worked',
        'status',
        'corrected_by_user_id',
        'server_corrected_at',
        'frozen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actual_started_at' => 'datetime',
            'actual_ended_at' => 'datetime',
            'minutes_worked' => 'integer',
            'server_corrected_at' => 'datetime',
            'frozen_at' => 'datetime',
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

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }
}
