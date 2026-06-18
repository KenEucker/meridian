<?php

namespace App\Services\Auth;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleOAuthService
{
    public const STATE_SESSION_KEY = 'auth.google_oauth_state';

    public function __construct(private readonly MagicLinkService $verifiedEmailAuth) {}

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->requiredConfig('client_id'),
            'redirect_uri' => $this->requiredConfig('redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'prompt' => 'select_account',
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
     * @param  array<string, mixed>  $profile
     */
    public function authenticateFromProfile(array $profile): User
    {
        $subject = $profile['sub'] ?? null;

        if (! is_string($subject) || trim($subject) === '') {
            throw new OAuthProviderException('Google did not return a stable subject.');
        }

        if (($profile['email_verified'] ?? false) !== true) {
            throw new UnverifiedProviderEmailException('Google did not return a verified email.');
        }

        $email = $profile['email'] ?? null;

        if (! is_string($email)) {
            throw new UnverifiedProviderEmailException('Google did not return a verified email.');
        }

        $normalizedEmail = $this->verifiedEmailAuth->normalizeEmail($email);

        if ($normalizedEmail === '' || ! filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new UnverifiedProviderEmailException('Google did not return a verified email.');
        }

        $user = $this->verifiedEmailAuth->resolveUserForVerifiedEmail($normalizedEmail);

        if ($user->isDisabled()) {
            throw new DisabledUserException('This account is disabled.');
        }

        $this->syncGoogleAuthIdentity($user, trim($subject), $normalizedEmail);

        Auth::login($user);

        return $user;
    }

    public function syncGoogleAuthIdentity(User $user, string $subject, string $normalizedEmail): AuthIdentity
    {
        return AuthIdentity::query()->updateOrCreate(
            [
                'provider' => AuthIdentity::PROVIDER_GOOGLE,
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
        $scopes = config('meridian.oauth.google.scopes', []);

        if (! is_array($scopes) || $scopes === []) {
            throw new OAuthConfigurationException('Google OAuth scopes are not configured.');
        }

        return array_values(array_map(
            static fn (mixed $scope): string => (string) $scope,
            $scopes,
        ));
    }

    private function exchangeCodeForAccessToken(string $code): string
    {
        if (trim($code) === '') {
            throw new OAuthProviderException('Google OAuth callback did not include a code.');
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
            throw new OAuthProviderException('Google token exchange could not connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new OAuthProviderException('Google token exchange failed.');
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new OAuthProviderException('Google token response did not include an access token.');
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
            throw new OAuthProviderException('Google userinfo request could not connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new OAuthProviderException('Google userinfo request failed.');
        }

        $profile = $response->json();

        if (! is_array($profile)) {
            throw new OAuthProviderException('Google userinfo response was invalid.');
        }

        return $profile;
    }

    private function requiredConfig(string $key): string
    {
        $value = config('meridian.oauth.google.'.$key);

        if (! is_string($value) || Str::of($value)->trim()->isEmpty()) {
            throw new OAuthConfigurationException("Google OAuth {$key} is not configured.");
        }

        return $value;
    }
}
