<?php

namespace App\Models;

use Database\Factories\EquipmentItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentItem extends Model
{
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
