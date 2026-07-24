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
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class Shift extends Model
{
    use AsSource;
    use Filterable;

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
        'schedule_lock_at',
        'cancelled_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'event_id' => Where::class,
        'department_id' => Where::class,
        'eligible_team_id' => Where::class,
        'title' => Like::class,
        'starts_at' => WhereDateStartEnd::class,
        'ends_at' => WhereDateStartEnd::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'event_id',
        'department_id',
        'eligible_team_id',
        'title',
        'starts_at',
        'ends_at',
        'capacity',
        'updated_at',
        'created_at',
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
            'schedule_lock_at' => 'datetime',
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

    public function attendanceOperations(): HasMany
    {
        return $this->hasMany(AttendanceOperation::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function hoursWorked(): HasMany
    {
        return $this->hasMany(HoursWorked::class);
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
     * Whether the shift configures a schedule lock/cutoff (SHIFT-009).
     */
    public function hasScheduleLock(): bool
    {
        return $this->schedule_lock_at !== null;
    }

    /**
     * Whether staff self-service schedule changes are locked at the given moment (SHIFT-009).
     *
     * When no schedule lock is configured, self-service changes remain allowed subject to other rules.
     */
    public function isScheduleLockedAt(?Carbon $moment = null): bool
    {
        if (! $this->hasScheduleLock()) {
            return false;
        }

        $moment ??= Carbon::now();

        return $moment->greaterThanOrEqualTo($this->schedule_lock_at);
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

    /**
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->whereHas('department', fn (Builder $department) => $department
            ->where('organization_id', $organizationId));
    }

    /**
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * @param  Builder<Shift>  $query
     * @return Builder<Shift>
     */
    public function scopeInTeam(Builder $query, string $teamId): Builder
    {
        return $query->where('eligible_team_id', $teamId);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
