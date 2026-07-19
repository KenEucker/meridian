<?php

namespace App\Models;

use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'department_id',
        'name',
        'description',
        'location_details',
        'map_location_id',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function currentAssignments(): HasMany
    {
        return $this->hasMany(CurrentDeploymentAssignment::class);
    }

    /**
     * @param  Builder<Deployment>  $query
     * @return Builder<Deployment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
