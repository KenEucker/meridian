<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rebuildable derived Name Reference token extracted from Field Report,
 * append, or Incident timeline source text (NR-001 through NR-014).
 *
 * This is a search/display artifact, not a person, alias, identity, entity,
 * suspect, volunteer profile, or independent source of truth. Source text on
 * the parent Field Report / append remains authoritative.
 */
class NameReferenceToken extends Model
{
    public const SOURCE_TYPE_FIELD_REPORT = 'field_report';

    public const SOURCE_TYPE_FIELD_REPORT_APPEND = 'field_report_append';

    public const SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY = 'incident_timeline_entry';

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Derived tokens only record a creation timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_type',
        'source_id',
        'field_report_id',
        'incident_id',
        'token',
        'normalized_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function sourceTypes(): array
    {
        return [
            self::SOURCE_TYPE_FIELD_REPORT,
            self::SOURCE_TYPE_FIELD_REPORT_APPEND,
            self::SOURCE_TYPE_INCIDENT_TIMELINE_ENTRY,
        ];
    }

    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
