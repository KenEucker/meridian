<?php

namespace App\Models;

use Database\Factories\IncidentTimelineEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only IMS incident history entry (INC-007, INC-014; technical spec
 * section 19.7; data/API specification section 10.16).
 */
class IncidentTimelineEntry extends Model
{
    /** @use HasFactory<IncidentTimelineEntryFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_INCIDENT_OPENED = 'incident_opened';

    public const TYPE_OPERATIONAL_NOTE = 'operational_note';

    public const TYPE_FIELD_UPDATED = 'incident_field_updated';

    public const TYPE_INCIDENT_LINKED = 'incident_linked';

    public const TYPE_INCIDENT_UNLINKED = 'incident_unlinked';

    public const TYPE_FIELD_REPORT_LINKED = 'field_report_linked';

    public const TYPE_FIELD_REPORT_UNLINKED = 'field_report_unlinked';

    public const TYPE_ATTACHMENT_STRICKEN = 'incident_attachment_stricken';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'incident_id',
        'actor_user_id',
        'entry_type',
        'body',
        'previous_value',
        'new_value',
        'reason',
        'created_at',
        'stricken_at',
        'stricken_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_value' => 'array',
            'new_value' => 'array',
            'created_at' => 'datetime',
            'stricken_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Incident timeline entries are append-only and cannot be edited.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Incident timeline entries are operational history and cannot be deleted.');
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
