<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class Event extends Model
{
    use AsSource;
    use Filterable;

    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'organization_id',
        'name',
        'slug',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'ic_department_id',
        'active_event_window_starts_at',
        'active_event_window_ends_at',
        'archived_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'organization_id' => Where::class,
        'name' => Like::class,
        'slug' => Like::class,
        'timezone' => Like::class,
        'status' => Like::class,
        'starts_at' => WhereDateStartEnd::class,
        'ends_at' => WhereDateStartEnd::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'organization_id',
        'name',
        'slug',
        'timezone',
        'status',
        'starts_at',
        'ends_at',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'active_event_window_starts_at' => 'datetime',
            'active_event_window_ends_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(EventApplication::class);
    }

    public function departmentAssignments(): HasMany
    {
        return $this->hasMany(EventDepartmentAssignment::class);
    }

    public function participatingDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'event_department_assignments')
            ->withPivot(['id', 'archived_at']);
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Route parameters for public apply routes ({organization:slug}/{event:slug}/apply).
     *
     * @return array{organization: Organization, event: self}
     */
    public function applyRouteParameters(): array
    {
        $this->loadMissing('organization');

        return [
            'organization' => $this->organization,
            'event' => $this,
        ];
    }
}
