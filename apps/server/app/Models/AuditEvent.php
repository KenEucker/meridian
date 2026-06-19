<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable system audit event (data/API specification section 14.1, technical
 * spec section 23). Audit rows are append-only: once written they cannot be
 * updated or deleted, preserving operational history (requirements 2.4).
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Audit events only record a creation timestamp.
     */
    public const UPDATED_AT = null;

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_WEB = 'web';

    public const SOURCE_ORCHID = 'orchid';

    public const SOURCE_API = 'api';

    public const SOURCE_SYNC = 'sync';

    public const SOURCE_CONSOLE = 'console';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'event_id',
        'department_id',
        'actor_user_id',
        'actor_device_id',
        'actor_node_id',
        'action',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'reason',
        'source_context',
        'signature_metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'signature_metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Audit events are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Audit events are immutable and cannot be deleted.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'actor_device_id');
    }

    public function actorNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'actor_node_id');
    }

    /**
     * Scope audit events to a specific entity type and identifier.
     *
     * @param  Builder<AuditEvent>  $query
     * @return Builder<AuditEvent>
     */
    public function scopeForEntity(Builder $query, string $entityType, int|string $entityId): Builder
    {
        return $query
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId);
    }
}
