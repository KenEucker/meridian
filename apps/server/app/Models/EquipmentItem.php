<?php

namespace App\Models;

use Database\Factories\EquipmentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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

    /**
     * One physical unit per record, identified by an asset tag or a serial
     * number, whose whereabouts Meridian follows unit by unit (EQUIP-010).
     */
    public const TRACKING_INDIVIDUAL = 'individual';

    /**
     * Interchangeable units of one kind held as a quantity on one record,
     * carrying no per-unit identifier (EQUIP-010).
     */
    public const TRACKING_POOLED = 'pooled';

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
        'tracking',
        'asset_tag',
        'serial_number',
        'quantity_total',
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
        'tracking' => Where::class,
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
        'tracking',
        'asset_tag',
        'serial_number',
        'quantity_total',
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
            'quantity_total' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function trackingKinds(): array
    {
        return [
            self::TRACKING_INDIVIDUAL,
            self::TRACKING_POOLED,
        ];
    }

    /**
     * Canonical tracking-kind labels (UI contract 9.6A).
     *
     * The stored value and the word on screen differ on purpose: `individual`
     * describes the record, "Tracked" describes what an operator does with it.
     *
     * @return array<string, string>
     */
    public static function trackingLabels(): array
    {
        return [
            self::TRACKING_INDIVIDUAL => 'Tracked',
            self::TRACKING_POOLED => 'Pooled',
        ];
    }

    public static function trackingLabel(?string $tracking): string
    {
        return self::trackingLabels()[$tracking] ?? self::trackingLabels()[self::TRACKING_INDIVIDUAL];
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

    /**
     * @param  Builder<EquipmentItem>  $query
     * @return Builder<EquipmentItem>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     * @return Builder<EquipmentItem>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     * @return Builder<EquipmentItem>
     */
    public function scopePooled(Builder $query): Builder
    {
        return $query->where('tracking', self::TRACKING_POOLED);
    }

    /**
     * @param  Builder<EquipmentItem>  $query
     * @return Builder<EquipmentItem>
     */
    public function scopeIndividuallyTracked(Builder $query): Builder
    {
        return $query->where('tracking', self::TRACKING_INDIVIDUAL);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isPooled(): bool
    {
        return $this->tracking === self::TRACKING_POOLED;
    }

    /**
     * How many units of a pool are out right now.
     *
     * Summed over open checkouts rather than read from a column, which is what
     * EQUIP-016 asks for: the checkouts are the record of what left the desk,
     * and a stored count beside them is a second answer that can disagree.
     */
    public function openCheckoutQuantity(): int
    {
        return (int) $this->checkouts()
            ->whereNull('returned_at')
            ->sum(DB::raw('quantity - COALESCE(quantity_returned, 0)'));
    }

    /**
     * The quantity available to hand out (EQUIP-016).
     *
     * A tracked unit answers 1 or 0 from its stored state, because that is what
     * "available" means for one physical thing. A pool answers its serviceable
     * total less what is out — and never reads `checked_out`, because a pool is
     * not wholly held by anybody.
     */
    public function availableQuantity(): int
    {
        if ($this->isArchived()) {
            return 0;
        }

        if (! $this->isPooled()) {
            return $this->canBeCheckedOut() ? 1 : 0;
        }

        return max(0, (int) $this->quantity_total - $this->openCheckoutQuantity());
    }

    public function canBeCheckedOut(): bool
    {
        if ($this->isPooled()) {
            return ! $this->isArchived() && $this->availableQuantity() > 0;
        }

        return in_array($this->status, self::checkoutReadyStatuses(), true);
    }
}
