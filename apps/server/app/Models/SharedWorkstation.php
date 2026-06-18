<?php

namespace App\Models;

use Database\Factories\SharedWorkstationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SharedWorkstation extends Model
{
    /** @use HasFactory<SharedWorkstationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'device_id',
        'event_id',
        'name',
        'trusted',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trusted' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function loginCodes(): HasMany
    {
        return $this->hasMany(SharedWorkstationLoginCode::class);
    }

    /**
     * @param  Builder<SharedWorkstation>  $query
     * @return Builder<SharedWorkstation>
     */
    public function scopeTrusted(Builder $query): Builder
    {
        return $query
            ->where('shared_workstations.trusted', true)
            ->whereNull('shared_workstations.revoked_at')
            ->whereHas('device', fn (Builder $query): Builder => $query->active());
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isTrusted(): bool
    {
        if (! $this->trusted || $this->isRevoked()) {
            return false;
        }

        $device = $this->relationLoaded('device')
            ? $this->device
            : $this->device()->first();

        return $device instanceof Device && ! $device->isRevoked();
    }
}
