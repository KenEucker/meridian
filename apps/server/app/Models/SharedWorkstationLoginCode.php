<?php

namespace App\Models;

use Database\Factories\SharedWorkstationLoginCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class SharedWorkstationLoginCode extends Model
{
    /** @use HasFactory<SharedWorkstationLoginCodeFactory> */
    use HasFactory, HasUuids;

    public const VALID_DURATION_WEEKS = 6;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'event_id',
        'shared_workstation_id',
        'code_hash',
        'expires_at',
        'generated_by_user_id',
        'used_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function expiresAtFrom(Carbon $generatedAt): Carbon
    {
        return $generatedAt->copy()->addWeeks(self::VALID_DURATION_WEEKS);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function sharedWorkstation(): BelongsTo
    {
        return $this->belongsTo(SharedWorkstation::class);
    }

    /**
     * @param  Builder<SharedWorkstationLoginCode>  $query
     * @return Builder<SharedWorkstationLoginCode>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('shared_workstation_login_codes.used_at')
            ->whereNull('shared_workstation_login_codes.revoked_at')
            ->where('shared_workstation_login_codes.expires_at', '>', now())
            ->whereHas('sharedWorkstation', fn (Builder $query): Builder => $query->trusted());
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
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
        if ($this->isUsed() || $this->isRevoked() || $this->isExpired($at)) {
            return false;
        }

        $workstation = $this->relationLoaded('sharedWorkstation')
            ? $this->sharedWorkstation
            : $this->sharedWorkstation()->first();

        return $workstation instanceof SharedWorkstation && $workstation->isTrusted();
    }
}
