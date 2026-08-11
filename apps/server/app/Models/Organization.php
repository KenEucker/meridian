<?php

namespace App\Models;

use App\Domain\Audit\AuditVerbosity;
use App\Domain\Staffing\ProfileChangePolicy;
use App\Services\Audit\AuditPolicy;
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
    /**
     * The documented VOL-028 default: two handle changes apply without review
     * before an organization's reviewers see one.
     */
    public const DEFAULT_HANDLE_SELF_SERVICE_CHANGE_LIMIT = 2;

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
        'default_placement_department_id',
        'default_credit_policy_id',
        'active_inactive_threshold_years',
        'prospective_inactive_threshold_years',
        'calendar_year_start_month',
        'calendar_year_start_day',
        'hours_correction_grace_period_days',
        'event_horizon_lead_days',
        'handle_change_policy',
        'profile_picture_change_policy',
        'handle_self_service_change_limit',
        'directory_enabled',
        'notifications_suppressed_at',
        'accepts_organization_applications',
        // How much audit history this organization writes, and how much it
        // keeps. Configured from God Mode only for now.
        'audit_verbosity',
        'audit_action_overrides',
        'audit_max_rows',
        'audit_max_bytes',
        'audit_retention_days',
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
            'hours_correction_grace_period_days' => 'integer',
            'event_horizon_lead_days' => 'integer',
            'directory_enabled' => 'boolean',
            'notifications_suppressed_at' => 'datetime',
            'accepts_organization_applications' => 'boolean',
            'archived_at' => 'datetime',
            'branding_palette_json' => 'array',
            'department_branding_enabled' => 'boolean',
            'branding_updated_at' => 'datetime',
            'audit_action_overrides' => 'array',
            'audit_max_rows' => 'integer',
            'audit_max_bytes' => 'integer',
            'audit_retention_days' => 'integer',
        ];
    }

    /**
     * Whether this organization's notification email is switched off
     * (NOTIFY-009).
     *
     * The switch is per organization rather than per node because the reason
     * to use it is per organization: an organization rehearsing an event on a
     * shared node must not mail its staff while the organization beside it
     * does. The global development suppression is separate and either alone
     * stops a send.
     */
    /**
     * Whether this organization takes applications that name no event
     * (APP-018).
     *
     * Off unless somebody turned it on. An organization that runs events
     * recruits into them, and an intake queue nobody agreed to review is a
     * queue that goes unread — so the public organization surface offers its
     * open events and no form of its own until this is set.
     */
    public function acceptsOrganizationApplications(): bool
    {
        return (bool) $this->accepts_organization_applications;
    }

    public function notificationsSuppressed(): bool
    {
        return $this->notifications_suppressed_at !== null;
    }

    /**
     * The same fact as a boolean, for form binding.
     *
     * The column is a timestamp because "suppressed since" is what an operator
     * wants when a restored backup stops mailing; a checkbox has no room for
     * that, so it reads the presence of the timestamp and writes it back.
     */
    public function getNotificationsSuppressedAttribute(): bool
    {
        return $this->notificationsSuppressed();
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

    /**
     * This organization's stated module state (MOD-005; data/API 10.1A).
     *
     * Rows here are statements, not the whole truth: a module with no row is
     * entitled and enabled, so what an organization actually runs is read from
     * {@see \App\Services\Modules\ActiveModuleResolver} rather than counted
     * here.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(OrganizationModule::class);
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

    public function defaultPlacementDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'default_placement_department_id');
    }

    /**
     * The ORG-017 hours correction window in days after event end, during
     * which authorized attendance managers may correct hours (HOURS-007) and
     * after which hours freeze (HOURS-008). Falls back to the documented
     * default of 14 for a row hydrated without the column.
     */
    public function hoursCorrectionGracePeriodDays(): int
    {
        return (int) ($this->hours_correction_grace_period_days ?? 14);
    }

    /**
     * The HORIZON-011 lead-up window in days before an event's active window
     * start, from which the Event Horizon begins to be presented (M18.38A;
     * technical spec 21D.4). Falls back to the documented default of 30 for a
     * row hydrated without the column. Held in days rather than as a date so
     * that moving an event moves the window with it, on the same reasoning as
     * the SHIFT-017 relative schedule cutoff.
     */
    public function eventHorizonLeadDays(): int
    {
        return (int) ($this->event_horizon_lead_days ?? 30);
    }

    /**
     * Whether this organization has the Directory (DIR-004). An organization
     * that has never touched the setting has one — enabled is the documented
     * default, and the fallback covers a row hydrated without the column.
     */
    public function directoryEnabled(): bool
    {
        return (bool) ($this->directory_enabled ?? true);
    }

    /**
     * How handle changes are decided here (VOL-027). An organization that has
     * never chosen reads as the documented default.
     */
    public function handleChangePolicy(): ProfileChangePolicy
    {
        return ProfileChangePolicy::resolve($this->handle_change_policy);
    }

    /** How profile picture submissions are decided here (VOL-027). */
    public function profilePictureChangePolicy(): ProfileChangePolicy
    {
        return ProfileChangePolicy::resolve($this->profile_picture_change_policy);
    }

    /**
     * How much of what happens here is written down.
     *
     * An organization that has never chosen reads as the documented default,
     * the way every other configuration column does. The floor is not part of
     * this answer: {@see AuditPolicy} applies it above
     * whatever this returns, so no level can reach below what requirements 2.4
     * and data/API section 8 oblige.
     */
    public function auditVerbosity(): AuditVerbosity
    {
        return AuditVerbosity::fromValue($this->audit_verbosity);
    }

    /**
     * Whether this organization has asked for a bound on its audit history at
     * all. Every limit is null by default, which is what every organization has
     * today and means unbounded.
     */
    public function hasAuditLimits(): bool
    {
        return $this->audit_max_rows !== null
            || $this->audit_max_bytes !== null
            || $this->audit_retention_days !== null;
    }

    /**
     * How many handle changes apply without review while the policy allows any
     * (VOL-028). Zero is a legitimate setting and means the policy's
     * self-service allowance is switched off without changing the policy.
     */
    public function handleSelfServiceChangeLimit(): int
    {
        return max(0, (int) ($this->handle_self_service_change_limit ?? self::DEFAULT_HANDLE_SELF_SERVICE_CHANGE_LIMIT));
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
