<?php

namespace App\Services\Auth;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\ApiTokenExpiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues Sanctum bearer tokens for Meridian client applications (AUTH-018,
 * AUTH-021, AUTH-024, AUTH-025; technical spec 11.4; data/API 5.4, 12.5).
 *
 * Every token is bound to a `devices` record and stamped with an expiry from the
 * node configuration. Stamping the row as well as configuring the guard is
 * deliberate: the guard requires both to pass, so lowering the node setting
 * expires tokens already issued, while the stored `expires_at` is what God Mode
 * reads when it lists tokens and what makes a token's own lifetime visible
 * without recomputing it.
 *
 * Issuance does not go through Sanctum's `createToken()`, because that helper
 * writes the row before a caller could add the device binding, and a token row
 * that exists unbound — even for the width of one statement — is the state
 * AUTH-021 exists to prevent. The row and its binding are written together
 * instead, in the same transaction as the audit entry that records the issuance.
 *
 * The plaintext token is returned to the caller and never stored, logged, or
 * audited (AUTH-025). Audit entries name the token by identifier and by bound
 * device.
 *
 * Issuance is also where the user/device pair becomes trusted (technical spec
 * 12.1, 12.4). The two are written in one transaction because they are one
 * event: a person proved who they are, from a device that proved it holds usable
 * key material, and there is no second ceremony in Alpha 1 to separate them.
 * Before this, nothing established trust at all — every Field Report a genuinely
 * signed-in person filed was refused as coming from an untrusted device.
 */
class ApiTokenIssuer
{
    public const AUDIT_ISSUED = 'api_token.issued';

    /** The client exchanged a mailed API login code (AUTH-019). */
    public const REASON_LOGIN_CODE = 'api_login_code';

    /** The client completed a Google or Discord handoff in the system browser (AUTH-020). */
    public const REASON_PROVIDER_HANDOFF = 'provider_handoff';

    public function __construct(
        private readonly AuditService $audit,
        private readonly DeviceTrustService $deviceTrust,
    ) {}

    /**
     * Issue a bearer token for a user, bound to the device that will hold it.
     *
     * @param  string|null  $clientName  what the client calls itself, shown to
     *                                   the user when tokens are listed
     * @param  string|null  $reason  which issuance path produced the token, so an
     *                               operator reading the audit trail can tell a
     *                               login code from a provider handoff
     */
    public function issue(
        User $user,
        Device $device,
        ?string $clientName = null,
        ?string $reason = null,
    ): NewAccessToken {
        $plainTextToken = $user->generateTokenString();
        $name = $this->resolveClientName($clientName);
        $expiresAt = $this->expiresAt();

        $token = DB::transaction(function () use ($user, $device, $plainTextToken, $name, $expiresAt, $reason): ApiToken {
            /** @var ApiToken $token */
            $token = $user->tokens()->create([
                'name' => $name,
                'token' => hash('sha256', $plainTextToken),
                'abilities' => ['*'],
                'expires_at' => $expiresAt,
                'device_id' => $device->getKey(),
            ]);

            $this->audit->recordForEntity(
                entity: $token,
                action: self::AUDIT_ISSUED,
                actorUser: $user,
                actorDevice: $device,
                after: [
                    // Identifier and bound device only. The token value exists
                    // in the response and nowhere else (AUTH-025).
                    'token_id' => $token->getKey(),
                    'device_id' => $device->getKey(),
                    'client_name' => $name,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                reason: $reason,
                sourceContext: AuditEvent::SOURCE_API,
            );

            /*
             * Same transaction as the token, because a token bound to a device
             * and that device being trusted for this user are the same fact
             * written twice. A revoked trust is left revoked and the token is
             * still issued: signing in is not how somebody undoes a revocation.
             */
            $this->deviceTrust->trust($user, $device);

            return $token;
        });

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    /**
     * When a token issued now expires.
     */
    public function expiresAt(): Carbon
    {
        return now()->addMinutes($this->expirationMinutes());
    }

    public function expirationMinutes(): int
    {
        $minutes = (int) config('meridian.api_tokens.expiration_minutes');

        return $minutes > 0 ? $minutes : ApiTokenExpiry::DEFAULT_MINUTES;
    }

    private function resolveClientName(?string $clientName): string
    {
        $name = trim((string) $clientName);

        if ($name === '') {
            return (string) config('meridian.api_tokens.default_client_name', 'Meridian client');
        }

        return Str::limit($name, 255, '');
    }
}
