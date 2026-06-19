<?php

namespace App\Models;

use Database\Factories\StaffOrganizationStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class StaffOrganizationStatus extends Model
{
    use AsSource;
    use Filterable;

    public const STATUS_PROSPECTIVE = 'prospective';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_EMERITUS = 'emeritus';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_DO_NOT_STAFF = 'do_not_staff';

    /** @use HasFactory<StaffOrganizationStatusFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'staff_id',
        'status',
        'status_reason',
        'status_changed_at',
        'status_changed_by_user_id',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'organization_id' => Where::class,
        'staff_id' => Where::class,
        'status' => Like::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'organization_id',
        'staff_id',
        'status',
        'status_changed_at',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
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
            self::STATUS_EMERITUS,
            self::STATUS_RETIRED,
            self::STATUS_DO_NOT_STAFF,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PROSPECTIVE => 'Prospective',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_EMERITUS => 'Emeritus',
            self::STATUS_RETIRED => 'Retired',
            self::STATUS_DO_NOT_STAFF => 'Do Not Staff',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }
}
