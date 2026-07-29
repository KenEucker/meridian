<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single-use API login code issued against an email address (AUTH-019;
 * technical spec 11.4; data/API 5.4).
 *
 * The raw code is never stored — see {@see \App\Services\Auth\ApiLoginCodeGenerator}
 * for the keyed hash — so this record can prove a code was issued and used
 * without being able to reproduce it.
 */
class ApiLoginCode extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'email',
        'code_hash',
        'expires_at',
        'attempts',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Codes that can still be redeemed.
     *
     * @param  Builder<ApiLoginCode>  $query
     * @return Builder<ApiLoginCode>
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::attemptLimit());
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->expires_at === null || $this->expires_at->lte($at);
    }

    public function hasExhaustedAttempts(): bool
    {
        return $this->attempts >= self::attemptLimit();
    }

    public function isRedeemable(?Carbon $at = null): bool
    {
        return ! $this->isUsed() && ! $this->isExpired($at) && ! $this->hasExhaustedAttempts();
    }

    public static function attemptLimit(): int
    {
        $limit = (int) config('meridian.api_tokens.login_code.attempt_limit', 5);

        return $limit > 0 ? $limit : 5;
    }
}
