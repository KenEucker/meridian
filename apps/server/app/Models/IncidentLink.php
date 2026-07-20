<?php

namespace App\Models;

use Database\Factories\IncidentLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Preserved related-incident relationship (M11.7B; INC-007 through INC-009,
 * INC-014; data/API 10.16).
 */
class IncidentLink extends Model
{
    /** @use HasFactory<IncidentLinkFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_RELATED = 'related';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'source_incident_id',
        'target_incident_id',
        'link_type',
        'created_by_user_id',
        'created_at',
        'unlinked_by_user_id',
        'unlinked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new RuntimeException('Incident links are operational history and cannot be deleted.');
        });
    }

    public function sourceIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'source_incident_id');
    }

    public function targetIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'target_incident_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function unlinkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_user_id');
    }
}
