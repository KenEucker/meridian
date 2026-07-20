<?php

namespace App\Models;

use Database\Factories\IncidentTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class IncidentType extends Model
{
    /** @use HasFactory<IncidentTypeFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'organization_id',
        'name',
        'created_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'incident_incident_types')
            ->withPivot(['id', 'created_at'])
            ->orderBy('incident_incident_types.created_at');
    }
}
