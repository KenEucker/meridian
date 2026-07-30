<?php

namespace App\Services\Auth;

use App\Models\ApiAuthHandoff;
use Illuminate\Support\Facades\DB;

/**
 * Google and Discord login for a client application, completed in the system
 * browser (AUTH-020; technical spec 11.4; data/API specification 5.4).
 *
 * The flow has three legs and this service owns all three:
 *
 * 1. A client starts a handoff, naming its provider, which client target it is,
 *    and a PKCE challenge. It receives the provider authorization URL and opens
 *    the system browser there.
 * 2. The provider returns the browser to this node's existing provider callback.
 *    That callback recognizes the state as a handoff and, instead of signing the
 *    browser in, resolves the identity and sends the browser on to the client's
 *    registered return address carrying a one-time exchange code.
 * 3. The client posts that code back with its PKCE verifier and its device, and
 *    receives a bearer token.
 *
 * The provider exchange is not reimplemented anywhere in the client: the node
 * performs it, exactly as it does for browser login, through the same
 * {@see OAuthProviderGateway} the web path uses.
 *
 * Two properties of the return leg drive the design. First, the browser is
 * returned to an address this node holds in its own configuration, never to one
 * the request supplied, so the handoff cannot be turned into an open redirect.
 * Second, on mobile and desktop that address is a custom scheme, and another
 * application on the same machine can register the same scheme; possession of
 * the exchange code therefore cannot be sufficient, which is what the PKCE
 * challenge is for (RFC 7636, and RFC 8252 for why native apps use the system
 * browser at all).
 *
 * Nothing here establishes a browser session. The person signing in is doing so
 * in a browser that is not the client application, and leaving a Meridian login
 * open there would be a session nobody signs out of.
 */
class ApiProviderHandoffService
{
    /** The person canceled or denied the provider consent screen. */
    public const FAILURE_PROVIDER_DENIED = 'provider_denied';

    /** The provider exchange or profile read failed. */
    public const FAILURE_PROVIDER_FAILED = 'provider_failed';

    /** The provider is not configured on this node. */
    public const FAILURE_PROVIDER_NOT_CONFIGURED = 'provider_not_configured';

    /** The provider returned no verified email, so no identity was resolved. */
    public const FAILURE_UNVERIFIED_EMAIL = 'unverified_email';

    /** The identity resolved to a disabled Meridian account. */
    public const FAILURE_ACCOUNT_DISABLED = 'account_disabled';

    /** The round trip took longer than the node allows. */
    public const FAILURE_EXPIRED = 'handoff_expired';

    /** The provider came back without an authorization code. */
    public const FAILURE_CALLBACK_INCOMPLETE = 'callback_incomplete';

    /** A second callback arrived for a handoff that had already been answered. */
    public const FAILURE_ALREADY_ANSWERED = 'handoff_already_answered';

    /**
     * PKCE challenge bounds from RFC 7636: a base64url SHA-256 digest is exactly
     * 43 characters, and the specification bounds a verifier at 128.
     */
    public const MIN_CODE_CHALLENGE_LENGTH = 43;

    public const MAX_CODE_CHALLENGE_LENGTH = 128;

    public function __construct(private readonly OAuthProviderRegistry $providers) {}

    /**
     * Begin a handoff and say where the system browser should be opened.
     *
     * @param  string  $codeChallenge  base64url SHA-256 of the client's verifier,
     *                                 already format-checked by the caller
     *
     * @throws ApiLoginException when the provider, the client target, or this
     *                           node's configuration cannot support the handoff
     */
    public function start(string $provider, string $clientTarget, string $codeChallenge): StartedApiAuthHandoff
    {
        $gateway = $this->providers->gateway($provider);

        if (! in_array($clientTarget, ApiAuthHandoff::clientTargets(), true)) {
            throw ApiLoginException::unknownClientTarget();
        }

        $redirectUri = $this->returnTargetFor($clientTarget);

        if ($redirectUri === null) {
            throw ApiLoginException::clientTargetNotConfigured($clientTarget);
        }

        $state = ApiAuthHandoffCodeGenerator::generate();

        // The authorization URL is built before the row is written, so a node
        // with an unconfigured provider records no handoff that could never have
        // been completed.
        try {
            $authorizationUrl = $gateway->authorizationUrl($state);
        } catch (OAuthConfigurationException) {
            throw ApiLoginException::providerNotConfigured($gateway->providerName());
        }

        $record = ApiAuthHandoff::query()->create([
            'provider' => $gateway->providerKey(),
            'client_target' => $clientTarget,
            'redirect_uri' => $redirectUri,
            'state_hash' => ApiAuthHandoffCodeGenerator::hash($state),
            'code_challenge' => $codeChallenge,
            'status' => ApiAuthHandoff::STATUS_PENDING,
            'expires_at' => now()->addMinutes($this->expiresMinutes()),
        ]);

        return new StartedApiAuthHandoff($record, $state, $authorizationUrl);
    }

    /**
     * Answer a provider callback that belongs to a handoff, returning where the
     * system browser should be sent next.
     *
     * Returns null when the state names no handoff, which is how the web login
     * callback knows the request is its own ordinary browser sign-in rather than
     * a client application's.
     *
     * Every other outcome returns a URL. Once a callback is known to belong to a
     * handoff, the browser goes back to the client that started it — including
     * on failure, because the client is the only place that can tell the person
     * what happened, and the system browser has no Meridian screen of its own
     * here.
     */
    public function completeCallback(
        string $provider,
        ?string $state,
        ?string $callbackCode,
        ?string $providerError,
    ): ?string {
        if ($state === null || $state === '') {
            return null;
        }

        $handoff = ApiAuthHandoff::query()
            ->where('provider', $provider)
            ->where('state_hash', ApiAuthHandoffCodeGenerator::hash($state))
            ->first();

        if (! $handoff instanceof ApiAuthHandoff) {
            return null;
        }

        // A handoff already answered is left exactly as it was. Overwriting a
        // successful one would retire an exchange code the client may not have
        // spent yet, which is a sign-in a replayed callback could cancel.
        if ($handoff->status !== ApiAuthHandoff::STATUS_PENDING) {
            return $this->returnUrl($handoff, $state, ['error' => self::FAILURE_ALREADY_ANSWERED]);
        }

        if ($handoff->isExpired()) {
            return $this->failed($handoff, $state, self::FAILURE_EXPIRED);
        }

        if ($providerError !== null && $providerError !== '') {
            return $this->failed($handoff, $state, self::FAILURE_PROVIDER_DENIED);
        }

        if ($callbackCode === null || trim($callbackCode) === '') {
            return $this->failed($handoff, $state, self::FAILURE_CALLBACK_INCOMPLETE);
        }

        try {
            $user = $this->providers->gateway($provider)->resolveUserFromCallbackCode($callbackCode);
        } catch (DisabledUserException) {
            return $this->failed($handoff, $state, self::FAILURE_ACCOUNT_DISABLED);
        } catch (UnverifiedProviderEmailException) {
            return $this->failed($handoff, $state, self::FAILURE_UNVERIFIED_EMAIL);
        } catch (OAuthConfigurationException) {
            return $this->failed($handoff, $state, self::FAILURE_PROVIDER_NOT_CONFIGURED);
        } catch (OAuthProviderException) {
            return $this->failed($handoff, $state, self::FAILURE_PROVIDER_FAILED);
        }

        $exchangeCode = ApiAuthHandoffCodeGenerator::generate();

        $handoff->forceFill([
            'user_id' => $user->getKey(),
            'exchange_code_hash' => ApiAuthHandoffCodeGenerator::hash($exchangeCode),
            'status' => ApiAuthHandoff::STATUS_AUTHENTICATED,
            'authenticated_at' => now(),
        ])->save();

        return $this->returnUrl($handoff, $state, ['code' => $exchangeCode]);
    }

    /**
     * Spend an exchange code, returning the completed handoff whose user a token
     * is now issued for.
     *
     * @throws ApiLoginException when the code is unknown, spent, expired, or
     *                           presented without the verifier that started it
     */
    public function redeem(string $exchangeCode, string $codeVerifier): ApiAuthHandoff
    {
        $codeHash = ApiAuthHandoffCodeGenerator::hash($exchangeCode);

        // Spending the code inside a locked read is what makes it single use:
        // two requests racing the same code cannot both find it redeemable.
        // Verification happens inside the same lock, so a caller that cannot
        // prove the handoff is theirs does not spend it either — the client that
        // does hold the verifier can still complete its sign-in, and an
        // intercepted code cannot be used to cancel one.
        return DB::transaction(function () use ($codeHash, $codeVerifier): ApiAuthHandoff {
            $handoff = ApiAuthHandoff::query()
                ->where('exchange_code_hash', $codeHash)
                ->redeemable()
                ->lockForUpdate()
                ->first();

            if (! $handoff instanceof ApiAuthHandoff) {
                throw ApiLoginException::invalidHandoff();
            }

            if (! $this->verifierMatches($codeVerifier, (string) $handoff->code_challenge)) {
                throw ApiLoginException::invalidCodeVerifier();
            }

            $handoff->forceFill([
                'status' => ApiAuthHandoff::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            return $handoff;
        });
    }

    public function expiresMinutes(): int
    {
        $minutes = (int) config('meridian.api_tokens.provider_handoff.expires_minutes', 10);

        return $minutes > 0 ? $minutes : 10;
    }

    /**
     * A client target with no configured return address is one this node does
     * not offer provider login for, so an empty setting disables it rather than
     * falling back to some other target's address.
     */
    private function returnTargetFor(string $clientTarget): ?string
    {
        $target = config('meridian.api_tokens.provider_handoff.return_targets.'.$clientTarget);

        if (! is_string($target)) {
            return null;
        }

        $target = trim($target);

        return $target === '' ? null : $target;
    }

    private function failed(ApiAuthHandoff $handoff, string $state, string $reason): string
    {
        $handoff->forceFill([
            'status' => ApiAuthHandoff::STATUS_FAILED,
            'failure_reason' => $reason,
        ])->save();

        return $this->returnUrl($handoff, $state, ['error' => $reason]);
    }

    /**
     * Build the address the system browser is sent to, carrying the handoff
     * state so the client can match the return to the sign-in it started.
     *
     * @param  array<string, string>  $params
     */
    private function returnUrl(ApiAuthHandoff $handoff, string $state, array $params): string
    {
        $redirectUri = (string) $handoff->redirect_uri;
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri.$separator.http_build_query(
            [...$params, 'state' => $state],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * PKCE S256 verification (RFC 7636). Only S256 is accepted; a plain
     * challenge would be no better than the code it is protecting.
     */
    private function verifierMatches(string $codeVerifier, string $codeChallenge): bool
    {
        $expected = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $expected);
    }
}
