<?php

namespace App\Models;

use Database\Factories\TrainingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Training extends Model
{
    /** @use HasFactory<TrainingFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const DELIVERY_IN_PERSON = 'in_person';

    public const DELIVERY_ONLINE = 'online';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'department_id',
        'team_id',
        'event_id',
        'name',
        'description',
        'expires_after_days',
        'delivery',
        'online_url',
        'scheduled_start_at',
        'scheduled_end_at',
        'location',
        'capacity',
        'time_commitment',
        'after_training',
        'provisions',
        'linked_shift_id',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_after_days' => 'integer',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'capacity' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function prerequisites(): HasMany
    {
        return $this->hasMany(TrainingPrerequisite::class);
    }

    /**
     * Trainings that must be completed before this training.
     */
    public function prerequisiteTrainings(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'training_prerequisites',
            'training_id',
            'prerequisite_training_id',
        )->withPivot(['id', 'created_at']);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(TrainingCompletion::class);
    }

    public function signups(): HasMany
    {
        return $this->hasMany(TrainingSignup::class);
    }

    /**
     * The shift this in-person training materialized as for shift-style
     * signup (event-bound scheduled sessions).
     */
    public function linkedShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'linked_shift_id');
    }

    public function isOnline(): bool
    {
        return $this->delivery === self::DELIVERY_ONLINE;
    }

    /**
     * Whether the MVP workflow requires scheduled attendance (and therefore a
     * signup/roster) for this training. Online trainings never require
     * signup; staff visit the training URL instead.
     */
    public function requiresScheduledAttendance(): bool
    {
        return ! $this->isOnline() && $this->scheduled_start_at !== null;
    }

    public function activeSignupCount(): int
    {
        return $this->signups()->active()->count();
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->activeSignupCount() >= $this->capacity;
    }

    /**
     * Whether the training expires after a configured number of days.
     */
    public function expires(): bool
    {
        return $this->expires_after_days !== null;
    }

    /**
     * Whether the training is bound to a single event occurrence.
     */
    public function isEventSpecific(): bool
    {
        return $this->event_id !== null;
    }

    /**
     * Whether the staff member has a current completion for this training (TRAIN-003).
     */
    public function isCompleteFor(Staff $staff, ?Carbon $moment = null): bool
    {
        return $this->completions()
            ->where('staff_id', $staff->id)
            ->current($moment)
            ->exists();
    }

    /**
     * @param  Builder<Training>  $query
     * @return Builder<Training>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
