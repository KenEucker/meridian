<?php

namespace App\Models;

use Database\Factories\DepartmentMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepartmentMembership extends Model
{
    public const STATUS_PROSPECTIVE = 'prospective';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_INELIGIBLE = 'ineligible';

    public const STATUS_EMERITUS = 'emeritus';

    public const STATUS_RETIRED = 'retired';

    /** @use HasFactory<DepartmentMembershipFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'staff_id',
        'status',
        'status_reason',
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

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PROSPECTIVE,
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
            self::STATUS_INELIGIBLE,
            self::STATUS_EMERITUS,
            self::STATUS_RETIRED,
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * @param  Builder<DepartmentMembership>  $query
     * @return Builder<DepartmentMembership>
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
