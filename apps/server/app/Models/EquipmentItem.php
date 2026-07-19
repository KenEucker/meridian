<?php

namespace App\Models;

use Database\Factories\EquipmentItemFactory;
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

class EquipmentItem extends Model
{
    use AsSource;
    use Filterable;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_MISSING = 'missing';

    public const STATUS_DAMAGED = 'damaged';

    /** @use HasFactory<EquipmentItemFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'event_id',
        'department_id',
        'name',
        'asset_tag',
        'serial_number',
        'status',
        'archived_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'organization_id' => Where::class,
        'event_id' => Where::class,
        'department_id' => Where::class,
        'name' => Like::class,
        'asset_tag' => Like::class,
        'serial_number' => Like::class,
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
        'event_id',
        'department_id',
        'name',
        'asset_tag',
        'serial_number',
        'status',
        'updated_at',
        'created_at',
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
            self::STATUS_AVAILABLE,
            self::STATUS_CHECKED_OUT,
            self::STATUS_RETURNED,
            self::STATUS_MISSING,
            self::STATUS_DAMAGED,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_AVAILABLE => 'Available',
            self::STATUS_CHECKED_OUT => 'Checked out',
            self::STATUS_RETURNED => 'Returned',
            self::STATUS_MISSING => 'Missing',
            self::STATUS_DAMAGED => 'Damaged',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'Not set';
        }

        return self::statusLabels()[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * @return list<string>
     */
    public static function checkoutReadyStatuses(): array
    {
        return [
            self::STATUS_AVAILABLE,
            self::STATUS_RETURNED,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(EquipmentCheckout::class);
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     * @return Builder<EquipmentItem>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function canBeCheckedOut(): bool
    {
        return in_array($this->status, self::checkoutReadyStatuses(), true);
    }
}
