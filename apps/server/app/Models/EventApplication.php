<?php

namespace App\Models;

use Database\Factories\EventApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

/**
 * Event-specific intake record (requirements APP-001 through APP-004, section 3.10;
 * data/API specification section 10.5).
 */
class EventApplication extends Model
{
    use AsSource;
    use Filterable;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_AUTO_REJECTED_DNS = 'auto_rejected_dns';

    /** @use HasFactory<EventApplicationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'organization_id',
        'staff_id',
        'applicant_email',
        'applicant_legal_name',
        'status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by_user_id',
        'decision_reason',
        'withdrawn_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'event_id' => Where::class,
        'organization_id' => Where::class,
        'staff_id' => Where::class,
        'applicant_email' => Like::class,
        'applicant_legal_name' => Like::class,
        'status' => Like::class,
        'submitted_at' => WhereDateStartEnd::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'event_id',
        'organization_id',
        'applicant_email',
        'applicant_legal_name',
        'status',
        'submitted_at',
        'reviewed_at',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * Canonical application statuses (APP-003; UI implementation contract section 9.3).
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_DEFERRED,
            self::STATUS_WITHDRAWN,
            self::STATUS_AUTO_REJECTED_DNS,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_DEFERRED => 'Deferred',
            self::STATUS_WITHDRAWN => 'Withdrawn',
            self::STATUS_AUTO_REJECTED_DNS => 'Auto-rejected due to DNS',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function departmentInterestRecords(): HasMany
    {
        return $this->hasMany(EventApplicationDepartmentInterest::class);
    }

    public function departmentInterests(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'event_application_department_interests')
            ->withPivot(['id'])
            ->withTimestamps();
    }

    /**
     * @param  Builder<EventApplication>  $query
     * @return Builder<EventApplication>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * @param  Builder<EventApplication>  $query
     * @return Builder<EventApplication>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Applications expressing interest in the department.
     *
     * @param  Builder<EventApplication>  $query
     * @return Builder<EventApplication>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query->whereHas('departmentInterests', fn (Builder $interest) => $interest
            ->where('departments.id', $departmentId));
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    public function departmentInterestDisplay(): string
    {
        /** @var Collection<int, Department> $departments */
        $departments = $this->relationLoaded('departmentInterests')
            ? $this->departmentInterests
            : $this->departmentInterests()->get();

        if ($departments->isEmpty()) {
            return 'No department preference';
        }

        return $departments
            ->sortBy('name')
            ->map(fn (Department $department): string => $department->name.($department->isArchived() ? ' (archived)' : ''))
            ->implode(', ');
    }
}
