<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class Team extends Model
{
    use AsSource;
    use Filterable;

    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        'code',
        'description',
        'is_default',
        'archived_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'department_id' => Where::class,
        'name' => Like::class,
        'code' => Like::class,
        'is_default' => Where::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'department_id',
        'name',
        'code',
        'is_default',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(TeamGrant::class);
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class, 'scope_id')
            ->where('scope_type', Waiver::SCOPE_TEAM);
    }

    public function eligibleShifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'eligible_team_id');
    }

    /**
     * @param  Builder<Team>  $query
     * @return Builder<Team>
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
