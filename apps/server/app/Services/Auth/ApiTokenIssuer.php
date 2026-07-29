<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\ApiTokenExpiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues and revokes Sanctum bearer tokens for Meridian client applications
 * (AUTH-018, AUTH-024; technical spec 11.4; data/API 5.4, 12.5).
 *
 * Every token is stamped with an expiry from the node configuration. Stamping
 * the row as well as configuring the guard is deliberate: the guard requires
 * both to pass, so lowering the node setting expires tokens already issued,
 * while the stored `expires_at` is what God Mode reads when it lists tokens and
 * what makes a token's own lifetime visible without recomputing it.
 *
 * Binding a token to a `devices` record — required by AUTH-021 before a token
 * may be issued at all — arrives with M16.2, along with God Mode listing and
 * revocation. This class is the one place issuance happens, so that binding
 * lands here rather than in each caller.
 */
class ApiTokenIssuer
{
    /**
     * Issue a bearer token for a user.
     *
     * @param  string|null  $clientName  what the client calls itself, shown to
     *                                   the user when tokens are listed
     */
    public function issue(User $user, ?string $clientName = null): NewAccessToken
    {
        return $user->createToken(
            $this->resolveClientName($clientName),
            ['*'],
            $this->expiresAt(),
        );
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
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
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
