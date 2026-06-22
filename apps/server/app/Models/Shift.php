<?php

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'signup_opens_at',
        'signup_closes_at',
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
            'signup_opens_at' => 'datetime',
            'signup_closes_at' => 'datetime',
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

    public function trainingRequirements(): HasMany
    {
        return $this->hasMany(ShiftTrainingRequirement::class);
    }

    public function waiverRequirements(): HasMany
    {
        return $this->hasMany(ShiftWaiverRequirement::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('removed_at');
    }

    /**
     * Trainings required before shift signup (SHIFT-005).
     */
    public function requiredTrainings(): BelongsToMany
    {
        return $this->belongsToMany(Training::class, 'shift_training_requirements')
            ->withPivot(['id', 'created_at']);
    }

    /**
     * Waivers required before shift signup (SHIFT-006).
     */
    public function requiredWaivers(): BelongsToMany
    {
        return $this->belongsToMany(Waiver::class, 'shift_waiver_requirements')
            ->withPivot(['id', 'created_at']);
    }

    /**
     * Whether the shift defines a maximum staff capacity (SHIFT-007).
     */
    public function hasCapacityLimit(): bool
    {
        return $this->capacity !== null;
    }

    /**
     * Whether active assignments have reached the configured capacity (SHIFT-012).
     */
    public function isAtCapacity(): bool
    {
        if (! $this->hasCapacityLimit()) {
            return false;
        }

        return $this->activeAssignments()->count() >= $this->capacity;
    }

    /**
     * Whether the shift configures signup availability dates (SHIFT-008).
     */
    public function hasSignupWindow(): bool
    {
        return $this->signup_opens_at !== null || $this->signup_closes_at !== null;
    }

    /**
     * Whether signup is open at the given moment based on configured availability dates (SHIFT-008).
     *
     * When no signup window is configured, signup is treated as open for later eligibility checks.
     */
    public function isSignupOpenAt(?Carbon $moment = null): bool
    {
        if (! $this->hasSignupWindow()) {
            return true;
        }

        $moment ??= Carbon::now();

        if ($this->signup_opens_at !== null && $moment->lt($this->signup_opens_at)) {
            return false;
        }

        if ($this->signup_closes_at !== null && $moment->gt($this->signup_closes_at)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the shift is scheduled within the given interval (SHIFT-002).
     */
    public function covers(Carbon $moment): bool
    {
        return $moment->betweenIncluded($this->starts_at, $this->ends_at);
    }

    /**
     * Whether this shift's scheduled interval overlaps another shift (SHIFT-014).
     *
     * Adjacent shifts that share a boundary do not overlap.
     */
    public function overlaps(Shift $other): bool
    {
        return $this->starts_at->lt($other->ends_at)
            && $other->starts_at->lt($this->ends_at);
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
