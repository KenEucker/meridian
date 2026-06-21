<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
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

class Organization extends Model
{
    use AsSource;
    use Filterable;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'organizers_department_id',
        'default_ic_department_id',
        'default_credit_policy_id',
        'active_inactive_threshold_years',
        'prospective_inactive_threshold_years',
        'calendar_year_start_month',
        'calendar_year_start_day',
        'archived_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'name' => Like::class,
        'slug' => Like::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'name',
        'slug',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_inactive_threshold_years' => 'integer',
            'prospective_inactive_threshold_years' => 'integer',
            'calendar_year_start_month' => 'integer',
            'calendar_year_start_day' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function policyDocuments(): HasMany
    {
        return $this->hasMany(PolicyDocument::class);
    }

    public function procedureDocuments(): HasMany
    {
        return $this->hasMany(ProcedureDocument::class);
    }

    public function documentFragments(): HasMany
    {
        return $this->hasMany(DocumentFragment::class);
    }

    public function documentAcknowledgmentRequirements(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgmentRequirement::class);
    }

    public function organizersDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'organizers_department_id');
    }

    public function staffOrganizationStatuses(): HasMany
    {
        return $this->hasMany(StaffOrganizationStatus::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'staff_organization_statuses')
            ->withPivot([
                'status',
                'status_reason',
                'status_changed_at',
                'status_changed_by_user_id',
            ])
            ->withTimestamps();
    }

    /**
     * @param  Builder<Organization>  $query
     * @return Builder<Organization>
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
