<?php

namespace App\Models;

use Database\Factories\DeviceTrustFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DeviceTrust extends Model
{
    /** @use HasFactory<DeviceTrustFactory> */
    use HasFactory, HasUuids;

    public const TRUST_DURATION_WEEKS = 6;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'device_id',
        'trusted_node_fingerprint',
        'first_trusted_at',
        'last_seen_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_trusted_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function expiresAtFrom(Carbon $trustedAt): Carbon
    {
        return $trustedAt->copy()->addWeeks(self::TRUST_DURATION_WEEKS);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @param  Builder<DeviceTrust>  $query
     * @return Builder<DeviceTrust>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('device_trusts.revoked_at')
            ->where('device_trusts.expires_at', '>', now())
            ->whereHas('device', fn (Builder $query): Builder => $query->active());
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->expires_at === null || $this->expires_at->lte($at);
    }

    public function isActive(?Carbon $at = null): bool
    {
        if ($this->isRevoked() || $this->isExpired($at)) {
            return false;
        }

        $device = $this->relationLoaded('device')
            ? $this->device
            : $this->device()->first();

        return $device instanceof Device && ! $device->isRevoked();
    }
}
