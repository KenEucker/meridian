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
     * Scope Field Reports submitted by a specific user (FR-004 author visibility).
     *
     * @param  Builder<FieldReport>  $query
     * @return Builder<FieldReport>
     */
    public function scopeForAuthor(Builder $query, User|string $user): Builder
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $query->where('submitted_by_user_id', (string) $userId);
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
}
