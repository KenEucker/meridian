<?php

namespace App\Models;

use Database\Factories\SharedWorkstationLoginCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;

class SharedWorkstationLoginCode extends Model
{
    /** @use HasFactory<SharedWorkstationLoginCodeFactory> */
    use AsSource, Filterable, HasFactory, HasUuids;

    public const VALID_DURATION_WEEKS = 6;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The columns the God Mode list may be sorted by. `code_hash` is deliberately
     * absent: nothing about the stored hash is a thing to order codes by.
     *
     * @var list<string>
     */
    protected $allowedSorts = [
        'created_at',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

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
     * The event a code is scoped to (data/API 12.4).
     *
     * A relation without a database foreign key: `shared_workstation_login_codes`
     * predates the `events` migration, and the formal constraint stays deferred
     * with the rest of the shared-workstation context columns.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
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

    /**
     * What an operator reading the God Mode list sees instead of the code, which
     * is never displayable: only its keyed hash is stored (data/API 12.4).
     *
     * Used is reported ahead of expiry, because a code that was used did its job
     * and then aged out; the reverse order would tell an operator a credential
     * expired unused when somebody had signed in with it.
     */
    public function statusLabel(): string
    {
        if ($this->isUsed()) {
            return __('Used');
        }

        if ($this->isRevoked()) {
            return __('Revoked');
        }

        if ($this->isExpired()) {
            return __('Expired');
        }

        $workstation = $this->relationLoaded('sharedWorkstation')
            ? $this->sharedWorkstation
            : $this->sharedWorkstation()->first();

        if (! $workstation instanceof SharedWorkstation || ! $workstation->isTrusted()) {
            return __('Workstation untrusted');
        }

        return __('Active');
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
