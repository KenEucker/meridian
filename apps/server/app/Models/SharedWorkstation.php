<?php

namespace App\Models;

use Database\Factories\SharedWorkstationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Screen\AsSource;

class SharedWorkstation extends Model
{
    /** @use HasFactory<SharedWorkstationFactory> */
    // `AsSource` because the God Mode pinning screen lists these rows (M18.32;
    // technical spec 13.1, "shared workstations are managed in God mode").
    use AsSource, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'device_id',
        'organization_id',
        'event_id',
        'department_id',
        'name',
        'short_code',
        'trusted',
        'context_pinned_at',
        'context_pinned_by_user_id',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trusted' => 'boolean',
            'context_pinned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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

    public function contextPinnedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'context_pinned_by_user_id');
    }

    public function loginCodes(): HasMany
    {
        return $this->hasMany(SharedWorkstationLoginCode::class);
    }

    public function signInRequests(): HasMany
    {
        return $this->hasMany(SharedWorkstationSignInRequest::class);
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

    public function hasPinnedKioskContext(): bool
    {
        return $this->organization_id !== null && $this->event_id !== null;
    }
}
