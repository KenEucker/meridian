<?php

namespace App\Services\Auth;

use App\Models\AuthIdentity;

/**
 * The external login providers Alpha 1 supports (technical spec 11.1).
 *
 * A provider arrives as a route parameter, so something has to turn the string
 * `google` into the service that speaks to Google without letting an arbitrary
 * string reach the container. This is that boundary: an unknown provider is a
 * refusal here rather than a resolution failure somewhere further in.
 */
class OAuthProviderRegistry
{
    public function __construct(
        private readonly GoogleOAuthService $google,
        private readonly DiscordOAuthService $discord,
    ) {}

    /**
     * @throws ApiLoginException when the provider is not one Meridian supports
     */
    public function gateway(string $provider): OAuthProviderGateway
    {
        return match ($provider) {
            AuthIdentity::PROVIDER_GOOGLE => $this->google,
            AuthIdentity::PROVIDER_DISCORD => $this->discord,
            default => throw ApiLoginException::unknownProvider($provider),
        };
    }
}
