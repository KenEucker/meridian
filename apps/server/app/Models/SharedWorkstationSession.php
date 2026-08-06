<?php

namespace App\Models;

use Database\Factories\SharedWorkstationSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A session established at a trusted shared workstation by a login code
 * (AUTH-030; technical spec 13.3; data/API 12.3).
 *
 * Not a token. AUTH-030 is explicit that entering a code establishes this and
 * does not issue a personal device token, and the two differ in every way that
 * matters: this is bound to a workstation rather than to a person's device, it
 * carries no trusted-device status, and it expires five minutes after the last
 * thing its user did rather than on a configured token lifetime (AUTH-024).
 *
 * Expiry is derived rather than stored. A workstation whose user walks away is
 * over five minutes later even though no request arrives to record it, so
 * {@see isActive()} answers from `last_activity_at` and `ended_at` only records
 * an end that was observed — which is what makes the reason meaningful.
 */
class SharedWorkstationSession extends Model
{
    /** @use HasFactory<SharedWorkstationSessionFactory> */
    use HasFactory, HasUuids;

    /**
     * Data/API 12.3 and technical spec 13.3: "inactivity timeout is 5 minutes
     * for MVP".
     *
     * A specification constant rather than node configuration, for the same
     * reason {@see SharedWorkstationLoginCode::VALID_DURATION_WEEKS} is: a
     * setting here would be a setting that could turn off a property the
     * requirements guarantee, on the one login path used by machines that
     * strangers stand in front of.
     */
    public const INACTIVITY_TIMEOUT_MINUTES = 5;

    /** The user ended the session themselves (technical spec 13.3). */
    public const ENDED_SIGNED_OUT = 'signed_out';

    /** Five minutes passed with nothing happening. */
    public const ENDED_TIMED_OUT = 'timed_out';

    /** A newer session at the same workstation replaced this one. */
    public const ENDED_SUPERSEDED = 'superseded';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'shared_workstation_id',
        'user_id',
        'event_id',
        'login_code_id',
        'session_key_hash',
        'started_at',
        'last_activity_at',
        // When the active user last proved, by typing a fresh login code, that
        // they are still the person at the keyboard (M18.32; UI contract 18.2).
        'reauthenticated_at',
        'ended_at',
        'ended_reason',
    ];

    /**
     * The raw session key is never an attribute of this model, but hiding the
     * hash keeps it out of anything that serializes a session record.
     *
     * @var list<string>
     */
    protected $hidden = [
        'session_key_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'reauthenticated_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function sharedWorkstation(): BelongsTo
    {
        return $this->belongsTo(SharedWorkstation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loginCode(): BelongsTo
    {
        return $this->belongsTo(SharedWorkstationLoginCode::class, 'login_code_id');
    }

    /**
     * The event the session is scoped to. A relation without a database foreign
     * key, matching {@see SharedWorkstationLoginCode::event()}.
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Sessions that have not been ended and have not timed out.
     *
     * @param  Builder<SharedWorkstationSession>  $query
     * @return Builder<SharedWorkstationSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('shared_workstation_sessions.ended_at')
            ->where('shared_workstation_sessions.last_activity_at', '>', self::inactiveBefore());
    }

    /**
     * Sessions with no recorded end, whether or not they have timed out.
     *
     * The set the start path has to close: a session that timed out unobserved
     * is not active, but it is still an open row, and leaving it open would mean
     * a workstation accumulated a history of sessions that never ended.
     *
     * @param  Builder<SharedWorkstationSession>  $query
     * @return Builder<SharedWorkstationSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('shared_workstation_sessions.ended_at');
    }

    /**
     * The moment a session that last saw activity at this instant expires.
     */
    public function expiresAt(): Carbon
    {
        return $this->last_activity_at->copy()->addMinutes(self::INACTIVITY_TIMEOUT_MINUTES);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    public function hasTimedOut(?Carbon $at = null): bool
    {
        return $this->expiresAt()->lte($at ?? now());
    }

    public function isActive(?Carbon $at = null): bool
    {
        return ! $this->hasEnded() && ! $this->hasTimedOut($at);
    }

    /**
     * Sessions whose last activity is older than this have timed out.
     */
    private static function inactiveBefore(): Carbon
    {
        return now()->subMinutes(self::INACTIVITY_TIMEOUT_MINUTES);
    }
}
