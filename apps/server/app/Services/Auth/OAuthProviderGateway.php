<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * One external login provider, as the rest of Meridian needs to use it
 * (technical spec 11.1, 11.4).
 *
 * Google and Discord differ only in their endpoints and in the shape of the
 * profile they return. Both are reached the same way: send the person to an
 * authorization URL, then resolve the Meridian user the returned code proves.
 * The provider handoff in {@see ApiProviderHandoffService} works against this
 * interface so that adding the second provider to it was not a second
 * implementation of the same flow.
 *
 * Resolution deliberately does not log anyone in. The web login path in
 * {@see \App\Http\Controllers\Auth\GoogleOAuthController} wants a browser
 * session; the provider handoff must not create one, because the browser doing
 * the signing in is the system browser and the credential belongs to the client
 * application that is waiting. Establishing a session there would leave a
 * Meridian login open in a browser nobody is going to sign out of.
 */
interface OAuthProviderGateway
{
    /**
     * The provider key used in `auth_identities.provider` and in route
     * parameters: `google` or `discord`.
     */
    public function providerKey(): string;

    /**
     * The provider's name as a person reads it.
     */
    public function providerName(): string;

    /**
     * Where to send the person to authorize, carrying the state this node will
     * recognize on the way back.
     *
     * @throws OAuthConfigurationException when the provider is not configured
     */
    public function authorizationUrl(string $state): string;

    /**
     * Resolve the Meridian user a completed provider callback authenticates,
     * without establishing a browser session.
     *
     * @throws OAuthConfigurationException when the provider is not configured
     * @throws OAuthProviderException when the provider exchange fails
     * @throws UnverifiedProviderEmailException when no verified email comes back
     * @throws DisabledUserException when the resolved account is disabled
     */
    public function resolveUserFromCallbackCode(string $code): User;
}
