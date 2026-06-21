<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'department_id',
        'eligible_team_id',
        'title',
        'department_name_snapshot',
        'team_name_snapshot',
        'starts_at',
        'ends_at',
        'capacity',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shift $shift): void {
            if ($shift->department_name_snapshot === null && $shift->department_id !== null) {
                $department = Department::query()->find($shift->department_id);

                if ($department !== null) {
                    $shift->department_name_snapshot = $department->name;
                }
            }

            if ($shift->team_name_snapshot === null && $shift->eligible_team_id !== null) {
                $team = Team::query()->find($shift->eligible_team_id);

                if ($team !== null) {
                    $shift->team_name_snapshot = $team->name;
                }
            }
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

    public function eligibleTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'eligible_team_id');
    }

    /**
     * Whether the shift defines a maximum staff capacity (SHIFT-007).
     */
    public function hasCapacityLimit(): bool
    {
        return $this->capacity !== null;
    }

    /**
     * Whether the shift is scheduled within the given interval (SHIFT-002).
     */
    public function covers(Carbon $moment): bool
    {
        return $moment->betweenIncluded($this->starts_at, $this->ends_at);
    }

    /**
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
