<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;
use RuntimeException;

/**
 * Incident Management System record (INC-001, INC-003, INC-004; technical
 * spec section 19; data/API specification sections 4.4 and 10.16).
 *
 * Incidents are event-specific operational records with server-assigned IMS
 * numbers. The body/history is append-only through incident timeline entries;
 * title/status edits stay allowed regardless of state in later M11 tasks.
 */
class Incident extends Model
{
    /**
     * `AsSource` and `Filterable` are for the God Mode repair screen (M18.34),
     * which lists and narrows this table. Neither adds a write path: that
     * screen reads, and an incident changes through the paths that record an
     * INC-007 timeline entry for the change.
     */
    use AsSource;
    use Filterable;

    /** @use HasFactory<IncidentFactory> */
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_ON_SCENE = 'on_scene';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_ROUTINE = 'Routine';

    public const PRIORITY_IMPORTANT = 'Important';

    public const PRIORITY_SERIOUS = 'Serious';

    public const PRIORITY_CRITICAL = 'Critical';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_id',
        'incident_number',
        'status',
        'priority_label',
        'started_at',
        'title',
        'location_name',
        'location_address',
        'location_details',
        'camp_id',
        'map_location_id',
        'created_by_user_id',
        'closed_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'incident_number' => Like::class,
        'title' => Like::class,
        'status' => Where::class,
        'priority_label' => Where::class,
        'started_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'incident_number',
        'title',
        'status',
        'priority_label',
        'started_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_ON_SCENE,
            self::STATUS_MONITORING,
            self::STATUS_ON_HOLD,
            self::STATUS_CLOSED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function priorityLabels(): array
    {
        return [
            self::PRIORITY_ROUTINE,
            self::PRIORITY_IMPORTANT,
            self::PRIORITY_SERIOUS,
            self::PRIORITY_CRITICAL,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new RuntimeException('Incidents are operational history and cannot be deleted.');
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->orderBy('created_at');
    }

    public function timelineEntries(): HasMany
    {
        return $this->hasMany(IncidentTimelineEntry::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function incidentStaff(): HasMany
    {
        return $this->hasMany(IncidentStaff::class)
            ->with('staff')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function incidentTypes(): BelongsToMany
    {
        return $this->belongsToMany(IncidentType::class, 'incident_incident_types')
            ->withPivot(['id', 'created_at'])
            ->orderBy('incident_incident_types.created_at');
    }

    public function fieldReportLinks(): HasMany
    {
        return $this->hasMany(IncidentFieldReport::class)
            ->orderBy('linked_at')
            ->orderBy('id');
    }

    public function sourceIncidentLinks(): HasMany
    {
        return $this->hasMany(IncidentLink::class, 'source_incident_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function targetIncidentLinks(): HasMany
    {
        return $this->hasMany(IncidentLink::class, 'target_incident_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Scope incidents to a single event (INC-001).
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeForEvent(Builder $query, Event|string $event): Builder
    {
        $eventId = $event instanceof Event ? $event->getKey() : $event;

        return $query->where('event_id', (string) $eventId);
    }

    /**
     * Organization narrowing for the God Mode list screen (M18.34).
     *
     * Organization is the only level an incident has. It carries no department
     * and no team: INC-001 makes an incident event-scoped, and who works it is
     * recorded as assigned staff rather than as an owning department. The
     * console filter bar shows only the levels a model declares, so the
     * department and team controls are absent here rather than present and
     * inert.
     *
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->whereHas('event', fn (Builder $event) => $event
            ->where('organization_id', $organizationId));
    }
}
