<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'device_label',
        'platform',
        'device_public_key',
        'first_seen_at',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function trusts(): HasMany
    {
        return $this->hasMany(DeviceTrust::class);
    }

    public function sharedWorkstations(): HasMany
    {
        return $this->hasMany(SharedWorkstation::class);
    }

    public function trustedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'device_trusts')
            ->withPivot([
                'trusted_node_fingerprint',
                'first_trusted_at',
                'last_seen_at',
                'expires_at',
                'revoked_at',
            ])
            ->withTimestamps();
    }

    /**
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
