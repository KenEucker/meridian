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
        'branding_display_name',
        'branding_palette_json',
        'branding_full_lockup_attachment_id',
        'branding_compact_mark_attachment_id',
        'department_branding_enabled',
        'branding_updated_at',
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
            'branding_palette_json' => 'array',
            'department_branding_enabled' => 'boolean',
            'branding_updated_at' => 'datetime',
        ];
    }

    /**
     * The name staff see on signed-in product surfaces (BRAND-001, BRAND-002).
     *
     * Falls back to the legal organization name rather than to "Meridian",
     * because an organization that uploaded a logo but never set a display
     * name has still asked to be presented as itself.
     */
    public function brandingDisplayName(): string
    {
        $displayName = trim((string) ($this->branding_display_name ?? ''));

        return $displayName !== '' ? $displayName : (string) $this->name;
    }

    /**
     * Whether this organization has said anything about its own identity. An
     * organization with no branding renders Meridian's defaults, and the
     * shell uses this to decide whether to replace the Meridian name and mark
     * at all (BRAND-002).
     */
    public function hasBrandingProfile(): bool
    {
        return $this->branding_palette_json !== null
            || trim((string) ($this->branding_display_name ?? '')) !== ''
            || $this->branding_full_lockup_attachment_id !== null
            || $this->branding_compact_mark_attachment_id !== null;
    }

    public function brandingFullLockup(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'branding_full_lockup_attachment_id');
    }

    public function brandingCompactMark(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'branding_compact_mark_attachment_id');
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

    public function defaultIcDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'default_ic_department_id');
    }

    /**
     * The organization default credit policy (ORG-009), used for any shift that
     * does not name its own (CREDIT-003).
     */
    public function defaultCreditPolicy(): BelongsTo
    {
        return $this->belongsTo(CreditPolicy::class, 'default_credit_policy_id');
    }

    public function creditPolicies(): HasMany
    {
        return $this->hasMany(CreditPolicy::class);
    }

    public function staffOrganizationStatuses(): HasMany
    {
        return $this->hasMany(StaffOrganizationStatus::class);
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class);
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
