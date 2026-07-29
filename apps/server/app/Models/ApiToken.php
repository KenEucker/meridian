<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;

/**
 * A Sanctum bearer token issued to a Meridian client application, bound to the
 * device that holds it (AUTH-021 through AUTH-023; technical spec 11.4;
 * data/API specification 12.5).
 *
 * Meridian replaces Sanctum's own token model — see
 * {@see \App\Providers\AppServiceProvider} — so that the device binding travels
 * with every token Sanctum reads, including the one the guard resolves on an
 * incoming request. Without the replacement, `device_id` would be a column no
 * runtime path could see.
 *
 * A token stays on record after it is revoked or expires. Deleting it would
 * leave God Mode unable to account for a credential that existed, and would
 * strand the audit entries that reference it by identifier (AUTH-025).
 */
class ApiToken extends SanctumPersonalAccessToken
{
    use AsSource;
    use Filterable;

    protected $table = 'personal_access_tokens';

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'name',
        'created_at',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'device_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this token would still authenticate a request.
     *
     * Revoking the device revokes its tokens (data/API 12.5), so a token on a
     * revoked device is not active even when its own `revoked_at` is empty.
     */
    public function isActive(): bool
    {
        return ! $this->isRevoked()
            && ! $this->isExpired()
            && $this->device?->isRevoked() !== true;
    }

    public function statusLabel(): string
    {
        if ($this->isRevoked()) {
            return __('Revoked');
        }

        if ($this->device?->isRevoked() === true) {
            return __('Device revoked');
        }

        if ($this->isExpired()) {
            return __('Expired');
        }

        return __('Active');
    }

    /**
     * @param  Builder<ApiToken>  $query
     * @return Builder<ApiToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $token) => $token
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->whereHas('device', fn (Builder $device) => $device->whereNull('revoked_at'));
    }

    /**
     * @param  Builder<ApiToken>  $query
     * @return Builder<ApiToken>
     */
    public function scopeForDevice(Builder $query, string $deviceId): Builder
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * @param  Builder<ApiToken>  $query
     * @return Builder<ApiToken>
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $userId);
    }
}
