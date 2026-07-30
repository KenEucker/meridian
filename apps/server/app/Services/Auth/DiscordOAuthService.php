<?php

namespace App\Services\Auth;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DiscordOAuthService implements OAuthProviderGateway
{
    public const STATE_SESSION_KEY = 'auth.discord_oauth_state';

    public function __construct(private readonly MagicLinkService $verifiedEmailAuth) {}

    public function providerKey(): string
    {
        return AuthIdentity::PROVIDER_DISCORD;
    }

    public function providerName(): string
    {
        return 'Discord';
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->requiredConfig('client_id'),
            'redirect_uri' => $this->requiredConfig('redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requiredConfig('authorize_url').'?'.$query;
    }

    public function authenticateFromCallbackCode(string $code): User
    {
        $accessToken = $this->exchangeCodeForAccessToken($code);
        $profile = $this->fetchUserProfile($accessToken);

        return $this->authenticateFromProfile($profile);
    }

    /**
     * Resolve the user a callback code authenticates without logging anyone in,
     * for the provider handoff in {@see ApiProviderHandoffService}.
     */
    public function resolveUserFromCallbackCode(string $code): User
    {
        $accessToken = $this->exchangeCodeForAccessToken($code);
        $profile = $this->fetchUserProfile($accessToken);

        return $this->resolveUserFromProfile($profile);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function authenticateFromProfile(array $profile): User
    {
        $user = $this->resolveUserFromProfile($profile);

        Auth::login($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function resolveUserFromProfile(array $profile): User
    {
        $subject = $profile['id'] ?? null;

        if (! is_string($subject) || trim($subject) === '') {
            throw new OAuthProviderException('Discord did not return a stable subject.');
        }

        if (($profile['verified'] ?? false) !== true) {
            throw new UnverifiedProviderEmailException('Discord did not return a verified email.');
        }

        $email = $profile['email'] ?? null;

        if (! is_string($email)) {
            throw new UnverifiedProviderEmailException('Discord did not return a verified email.');
        }

        $normalizedEmail = $this->verifiedEmailAuth->normalizeEmail($email);

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new UnverifiedProviderEmailException('Discord did not return a verified email.');
        }

        $user = $this->verifiedEmailAuth->resolveUserForVerifiedEmail($normalizedEmail);

        if ($user->isDisabled()) {
            throw new DisabledUserException('This account is disabled.');
        }

        $this->syncDiscordAuthIdentity($user, trim($subject), $normalizedEmail);

        return $user;
    }

    public function syncDiscordAuthIdentity(User $user, string $subject, string $normalizedEmail): AuthIdentity
    {
        return AuthIdentity::query()->updateOrCreate(
            [
                'provider' => AuthIdentity::PROVIDER_DISCORD,
                'provider_subject' => $subject,
            ],
            [
                'user_id' => $user->id,
                'provider_email' => $normalizedEmail,
                'provider_email_verified' => true,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = config('meridian.oauth.discord.scopes', []);

        if (! is_array($scopes) || $scopes === []) {
            throw new OAuthConfigurationException('Discord OAuth scopes are not configured.');
        }

        return array_values(array_map(
            static fn (mixed $scope): string => (string) $scope,
            $scopes,
        ));
    }

    private function exchangeCodeForAccessToken(string $code): string
    {
        if (trim($code) === '') {
            throw new OAuthProviderException('Discord OAuth callback did not include a code.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->post($this->requiredConfig('token_url'), [
                    'client_id' => $this->requiredConfig('client_id'),
                    'client_secret' => $this->requiredConfig('client_secret'),
                    'redirect_uri' => $this->requiredConfig('redirect_uri'),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ]);
        } catch (ConnectionException $exception) {
            throw new OAuthProviderException('Discord token exchange could not connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new OAuthProviderException('Discord token exchange failed.');
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new OAuthProviderException('Discord token response did not include an access token.');
        }

        return $accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserProfile(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get($this->requiredConfig('userinfo_url'));
        } catch (ConnectionException $exception) {
            throw new OAuthProviderException('Discord user request could not connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new OAuthProviderException('Discord user request failed.');
        }

        $profile = $response->json();

        if (! is_array($profile)) {
            throw new OAuthProviderException('Discord user response was invalid.');
        }

        return $profile;
    }

    private function requiredConfig(string $key): string
    {
        $value = config('meridian.oauth.discord.'.$key);

        if (! is_string($value) || Str::of($value)->trim()->isEmpty()) {
            throw new OAuthConfigurationException("Discord OAuth {$key} is not configured.");
        }

        return $value;
    }
}
