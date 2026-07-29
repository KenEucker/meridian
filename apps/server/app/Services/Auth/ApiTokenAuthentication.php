<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\User;
use App\Services\Audit\AuditService;

/**
 * Request-time evaluation of an issued bearer token (AUTH-023, AUTH-025;
 * technical spec 11.4; data/API 12.5).
 *
 * Sanctum resolves a token from the `Authorization` header on every request and
 * asks this class whether it still authenticates. Evaluating revocation here,
 * rather than at the moment of revoking, is what makes revocation take effect
 * "at the next request the node receives from it" without the client
 * cooperating: nothing is pushed to the device, and the device cannot decline
 * to be revoked.
 *
 * Three things end a token: its own revocation, the revocation of the device it
 * is bound to (data/API 12.5: "revoking a device revokes its tokens"), and
 * expiry. A token whose device row has gone missing is also refused — the
 * binding is the unit of revocation, and a token no device answers for cannot be
 * revoked as hardware.
 */
class ApiTokenAuthentication
{
    public const AUDIT_EXPIRED = 'api_token.expired';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  mixed  $accessToken  the token Sanctum resolved from the request
     * @param  bool  $isValid  Sanctum's own verdict, which covers the token's
     *                         `expires_at` and the node's configured lifetime
     */
    public function accepts(mixed $accessToken, bool $isValid): bool
    {
        if (! $accessToken instanceof ApiToken) {
            return $isValid;
        }

        if ($accessToken->isRevoked()) {
            return false;
        }

        $device = $accessToken->device()->first();

        if (! $device instanceof Device || $device->isRevoked()) {
            return false;
        }

        if (! $isValid) {
            // Revocation and device revocation are handled above, so Sanctum's
            // remaining refusals are the two forms of lifetime: the token's own
            // `expires_at`, and the node-configured lifetime measured from when
            // the token was issued.
            $this->recordExpiry($accessToken, $device);

            return false;
        }

        return true;
    }

    /**
     * Audit a token's expiry, once (AUTH-025).
     *
     * Expiry is the one audited moment in a token's life that nobody performs.
     * It is recorded when the node first observes it — the next request the
     * expired token makes — because that is when the node learns of it and the
     * earliest point an entry could name a real occurrence. A client that keeps
     * presenting the same expired token does not keep writing entries.
     */
    private function recordExpiry(ApiToken $token, Device $device): void
    {
        $alreadyRecorded = AuditEvent::query()
            ->forEntity($token->getMorphClass(), (string) $token->getKey())
            ->where('action', self::AUDIT_EXPIRED)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $owner = $token->tokenable()->first();

        $this->audit->recordForEntity(
            entity: $token,
            action: self::AUDIT_EXPIRED,
            actorUser: $owner instanceof User ? $owner : null,
            actorDevice: $device,
            after: [
                // Identifier and bound device only, never the token value
                // (AUTH-025).
                'token_id' => $token->getKey(),
                'device_id' => $device->getKey(),
                'client_name' => $token->name,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
            sourceContext: AuditEvent::SOURCE_API,
        );
    }
}
