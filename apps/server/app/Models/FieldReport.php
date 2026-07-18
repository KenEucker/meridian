<?php

namespace App\Models;

use Database\Factories\FieldReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable Field Report (data/API specification section 10.15, technical
 * spec section 17, FR-001 through FR-007).
 *
 * Original body never changes after submission. Appends, FRA numbering,
 * offline create/sync, IC visibility, photos, and Name References belong to
 * later M9 tasks.
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
        'event_id',
        'department_id',
        'team_id',
        'submitted_by_user_id',
        'staff_id',
        'fra_number',
        'temporary_local_number',
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
