<?php

namespace App\Models;

use Database\Factories\FieldReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;
use RuntimeException;

/**
 * Immutable Field Report (data/API specification section 10.15, technical
 * spec section 17, FR-001 through FR-009, FR-012).
 *
 * Original title and body never change after submission (FR-003, FR-007).
 * Server acceptance and FRA numbering are implemented by M9.3; IC event-wide
 * visibility is enforced by FieldReportPolicy / FieldReportVisibilityAccess
 * (M9.5). Append-only additions are created through FieldReportAppendService
 * (M9.6) and cannot add or alter titles. Name References are parsed from body
 * text only into a rebuildable derived index after acceptance (M9.6A). Photo
 * attachment sync and storage are owned by M9.8. Incident-note copy uses
 * FieldReportIncidentNoteCopy (M9.7A contract; M11.8 wiring).
 */
class FieldReport extends Model
{
    /**
     * `AsSource` and `Filterable` are for the God Mode repair screen (M18.34),
     * which lists and narrows this table. Neither adds a write path: the model
     * refuses updates and deletes below, and technical spec 22.3 rules out a
     * console edit of a finalized body.
     */
    use AsSource;
    use Filterable;

    /** @use HasFactory<FieldReportFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Field reports only record a creation timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_id',
        'department_id',
        'team_id',
        'submitted_by_user_id',
        'staff_id',
        'fra_number',
        'temporary_local_number',
        'title',
        'body',
        'device_submitted_at',
        'server_received_at',
        'origin_device_id',
        'origin_node_id',
        'sync_status',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'fra_number' => Like::class,
        'temporary_local_number' => Like::class,
        'title' => Like::class,
        'sync_status' => Where::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'fra_number',
        'title',
        'sync_status',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_submitted_at' => 'datetime',
            'server_received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Field reports are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Field reports are immutable and cannot be deleted.');
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

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function originDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'origin_device_id');
    }

    public function originNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'origin_node_id');
    }

    public function appends(): HasMany
    {
        return $this->hasMany(FieldReportAppend::class)
            ->orderBy('device_submitted_at')
            ->orderBy('created_at');
    }

    public function nameReferenceTokens(): HasMany
    {
        return $this->hasMany(NameReferenceToken::class);
    }

    public function incidentLinks(): HasMany
    {
        return $this->hasMany(IncidentFieldReport::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->orderBy('created_at');
    }

    /**
     * Scope Field Reports this user authored or took (FR-004 author visibility;
     * FR-015).
     *
     * Two columns, because a taken report has two people on it: the reporting
     * staff member it belongs to and the operator who wrote it down. Both reach
     * it — the author because it is their account, the submitter because they
     * are the one who typed it. Neither is rewritten to look like the other.
     *
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function scopeForAuthor(Builder $query, User|string $user): Builder
    {
        $userId = (string) ($user instanceof User ? $user->getKey() : $user);

        return $query->where(function (Builder $scope) use ($userId): void {
            $scope
                ->where('submitted_by_user_id', $userId)
                ->orWhereHas('staff', fn (Builder $staff) => $staff
                    ->whereHas('users', fn (Builder $users) => $users->whereKey($userId)));
        });
    }

    /**
     * Whether this report's recorded author is this user (FR-009, FR-016).
     *
     * Resolved through the staff record rather than through
     * `submitted_by_user_id`, because on a taken report those are two different
     * people and append authority follows the author. An operator who took
     * fifty reports may append to none of them.
     */
    public function isAuthoredBy(User $user): bool
    {
        return $this->authorUserIds()->contains((string) $user->getKey());
    }

    /** Whether this user is the one who put the report into Meridian (FR-015). */
    public function wasSubmittedBy(User $user): bool
    {
        return (string) $this->submitted_by_user_id === (string) $user->getKey();
    }

    /**
     * Whether this report was taken for its author by somebody else (FR-015).
     *
     * Derived from the two identifiers already on the record. Storing it would
     * be a third fact that can disagree with the two it summarises.
     */
    public function wasTakenOnBehalf(): bool
    {
        if ($this->staff_id === null || $this->submitted_by_user_id === null) {
            return false;
        }

        return ! $this->authorUserIds()->contains((string) $this->submitted_by_user_id);
    }

    /**
     * The users the author staff record answers to.
     *
     * Read from the loaded relation when a caller has eager-loaded
     * `staff.users`, which is what keeps a list of an event's Field Reports
     * from asking one question per row.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function authorUserIds(): \Illuminate\Support\Collection
    {
        if ($this->staff_id === null) {
            return collect();
        }

        if ($this->relationLoaded('staff') && $this->staff?->relationLoaded('users') === true) {
            return $this->staff->users->map(fn (User $user): string => (string) $user->getKey());
        }

        return $this->staff()->first()?->users()->pluck('users.id')
            ->map(fn (mixed $id): string => (string) $id)
            ?? collect();
    }

    /**
     * Scope Field Reports to a single event (FR-002).
     *
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function scopeForEvent(Builder $query, Event|string $event): Builder
    {
        $eventId = $event instanceof Event ? $event->getKey() : $event;

        return $query->where('event_id', (string) $eventId);
    }

    /**
     * The organization / department / team narrowing the God Mode list screen
     * offers (M18.34).
     *
     * A report has no organization column: it belongs to an event, and the
     * event belongs to the organization. Going through the event rather than
     * through the department is deliberate — `department_id` is nullable on a
     * report filed against no department, and narrowing by organization must
     * not quietly drop those.
     *
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->whereHas('event', fn (Builder $event) => $event
            ->where('organization_id', $organizationId));
    }

    /**
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function scopeInTeam(Builder $query, string $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }
}
