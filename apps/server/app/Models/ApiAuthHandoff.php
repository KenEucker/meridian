<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Google or Discord login handed from a client application to the system
 * browser and back (AUTH-020; technical spec 11.4; data/API specification 5.4).
 *
 * The client starts a handoff and opens the system browser at the provider. The
 * provider returns the browser to this node, which resolves the identity and
 * sends the browser on to the client's registered return target carrying a
 * one-time exchange code. The client posts that code back for a bearer token.
 *
 * Neither credential on the record is stored as a value: see
 * {@see \App\Services\Auth\ApiAuthHandoffCodeGenerator} for the keyed hash. The
 * row can therefore prove a handoff happened without being able to reproduce
 * anything that would complete one.
 */
class ApiAuthHandoff extends Model
{
    use HasUuids;

    /** Started by a client; the provider round trip has not returned yet. */
    public const STATUS_PENDING = 'pending';

    /** The provider callback resolved an identity; the exchange code is live. */
    public const STATUS_AUTHENTICATED = 'authenticated';

    /** The exchange code was spent for a bearer token. */
    public const STATUS_COMPLETED = 'completed';

    /** The provider round trip did not produce a usable identity. */
    public const STATUS_FAILED = 'failed';

    /** The web client, which returns to an HTTPS address on its own origin. */
    public const TARGET_WEB = 'web';

    /** The mobile Field application, which returns through a custom scheme. */
    public const TARGET_MOBILE = 'mobile';

    /** The desktop application, which returns through a custom scheme. */
    public const TARGET_DESKTOP = 'desktop';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'provider',
        'client_target',
        'redirect_uri',
        'state_hash',
        'code_challenge',
        'exchange_code_hash',
        'user_id',
        'status',
        'failure_reason',
        'expires_at',
        'authenticated_at',
        'completed_at',
    ];

    /**
     * Every client target Meridian issues tokens to (technical spec 11.4: the
     * web client, the mobile Field application, and the desktop application all
     * use the same mechanism).
     *
     * @return list<string>
     */
    public static function clientTargets(): array
    {
        return [self::TARGET_WEB, self::TARGET_MOBILE, self::TARGET_DESKTOP];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->expires_at === null || $this->expires_at->lte($at);
    }

    /**
     * Handoffs a provider callback may still complete.
     *
     * @param  Builder<ApiAuthHandoff>  $query
     * @return Builder<ApiAuthHandoff>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Handoffs whose exchange code may still be spent for a token.
     *
     * @param  Builder<ApiAuthHandoff>  $query
     * @return Builder<ApiAuthHandoff>
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_AUTHENTICATED)
            ->whereNotNull('user_id')
            ->where('expires_at', '>', now());
    }
}
