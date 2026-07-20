<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_ON_SCENE = 'on_scene';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_CLOSED = 'closed';

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

    public function fieldReportLinks(): HasMany
    {
        return $this->hasMany(IncidentFieldReport::class)
            ->orderBy('linked_at')
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
}
