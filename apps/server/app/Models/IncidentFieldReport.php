<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only relationship between an Incident and Field Report (data/API
 * 10.16). M11.6A reads active links for incident Name Reference chips/search;
 * M11.8 owns the link/unlink command workflow.
 */
class IncidentFieldReport extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'incident_id',
        'field_report_id',
        'linked_by_user_id',
        'linked_at',
        'unlinked_by_user_id',
        'unlinked_at',
        'stricken_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new RuntimeException('Incident Field Report links are operational history and cannot be deleted.');
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class);
    }

    public function linkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function unlinkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlinked_by_user_id');
    }
}
