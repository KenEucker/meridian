<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes issued bearer tokens (AUTH-022, AUTH-023, AUTH-025; technical spec
 * 11.4; data/API 12.5).
 *
 * Revocation stamps `revoked_at` rather than deleting the row. The guard reads
 * that stamp on every request — see {@see ApiTokenAuthentication} — so a revoked
 * token stops authenticating on its next request without the client cooperating,
 * and the token stays on record for an operator to account for afterwards.
 *
 * Revoking is idempotent. A token revoked twice keeps the timestamp and the
 * audit entry from the first revocation, because the second one changed nothing
 * and an audit trail that says otherwise is worse than no entry at all.
 */
class ApiTokenRevoker
{
    public const AUDIT_REVOKED = 'api_token.revoked';

    /** The client holding the token disposed of it itself. */
    public const REASON_CLIENT = 'client_signed_out';

    /** God Mode revoked this one token (AUTH-022). */
    public const REASON_GOD_MODE_TOKEN = 'god_mode_token';

    /** God Mode revoked every token on the bound device (AUTH-022). */
    public const REASON_GOD_MODE_DEVICE = 'god_mode_device';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Revoke one token.
     *
     * @param  User|null  $actor  the operator revoking it, or null when the
     *                            holder revoked their own token
     * @return bool whether this call is what revoked it
     */
    public function revoke(
        ApiToken $token,
        ?User $actor,
        string $reason,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): bool {
        if ($token->isRevoked()) {
            return false;
        }

        DB::transaction(function () use ($token, $actor, $reason, $sourceContext): void {
            $token->forceFill(['revoked_at' => now()])->save();

            $this->audit->recordForEntity(
                entity: $token,
                action: self::AUDIT_REVOKED,
                actorUser: $actor,
                actorDevice: $token->device()->first(),
                after: [
                    // Identifier and bound device only, never the token value
                    // or its hash (AUTH-025).
                    'token_id' => $token->getKey(),
                    'device_id' => $token->device_id,
                    'client_name' => $token->name,
                    'revoked_at' => $token->revoked_at?->toIso8601String(),
                ],
                reason: $reason,
                sourceContext: $sourceContext,
            );
        });

        return true;
    }

    /**
     * Revoke every token bound to a device (AUTH-022).
     *
     * Each token is revoked and audited in its own right, so a later reader can
     * account for every credential the device held rather than one summary
     * entry naming a count.
     *
     * @return int how many tokens this call revoked
     */
    public function revokeForDevice(Device $device, ?User $actor): int
    {
        $revoked = 0;

        ApiToken::query()
            ->forDevice((string) $device->getKey())
            ->whereNull('revoked_at')
            ->get()
            ->each(function (ApiToken $token) use ($actor, &$revoked): void {
                if ($this->revoke($token, $actor, self::REASON_GOD_MODE_DEVICE)) {
                    $revoked++;
                }
            });

        return $revoked;
    }

    /**
     * Revoke the token the current request authenticated with, so the client
     * that holds it stops authenticating on its next request.
     */
    public function revokeCurrentToken(User $user): void
    {
        $token = $user->currentAccessToken();

        // Only a real issued token can be revoked. Sanctum hands back a
        // transient placeholder when a request authenticated some other way,
        // and there is nothing on record to revoke in that case.
        if ($token instanceof ApiToken) {
            $this->revoke($token, $user, self::REASON_CLIENT, AuditEvent::SOURCE_API);

            return;
        }

        // A token model other than Meridian's cannot carry a revocation stamp,
        // so falling back to deletion keeps the credential from authenticating
        // again. Reachable only if the model swap in AppServiceProvider is
        // undone.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
