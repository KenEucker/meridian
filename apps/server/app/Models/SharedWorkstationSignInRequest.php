<?php

namespace App\Models;

use Database\Factories\SharedWorkstationSignInRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sign-in request a locked trusted workstation opened and presented as a
 * scannable code (AUTH-032 through AUTH-037; technical spec 13.4; data/API
 * 12.4A).
 *
 * The id is the public half — it travels in the QR and grants nothing. The
 * pickup secret is the private half, held only by the opening workstation and
 * stored here only as a keyed hash. A request expires, grants at most once,
 * and is collectable at most once, only by the opener.
 */
class SharedWorkstationSignInRequest extends Model
{
    /** @use HasFactory<SharedWorkstationSignInRequestFactory> */
    use HasFactory, HasUuids;

    /** The request signs its granting user in at the workstation (AUTH-035). */
    public const PURPOSE_SIGN_IN = 'sign_in';

    /**
     * The request re-confirms the live session's own user (AUTH-036); it is
     * bound to that session and collects no new one.
     */
    public const PURPOSE_REAUTHENTICATION = 'reauthentication';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'shared_workstation_id',
        'event_id',
        'purpose',
        'shared_workstation_session_id',
        'pickup_secret_hash',
        'granted_by_user_id',
        'granted_at',
        'collected_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'collected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function sharedWorkstation(): BelongsTo
    {
        return $this->belongsTo(SharedWorkstation::class);
    }

    /**
     * The event the request was opened under. A relation without a database
     * foreign key, matching the other shared-workstation context columns.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /** The live session a re-authentication request is bound to (AUTH-036). */
    public function session(): BelongsTo
    {
        return $this->belongsTo(SharedWorkstationSession::class, 'shared_workstation_session_id');
    }

    public function isExpired(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->expires_at === null || $this->expires_at->lte($at);
    }

    public function isGranted(): bool
    {
        return $this->granted_at !== null;
    }

    public function isCollected(): bool
    {
        return $this->collected_at !== null;
    }

    /**
     * @param  Builder<SharedWorkstationSignInRequest>  $query
     * @return Builder<SharedWorkstationSignInRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('collected_at')
            ->where('expires_at', '>', now());
    }
}
