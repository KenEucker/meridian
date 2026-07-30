<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthIdentity;
use App\Services\Auth\ApiProviderHandoffService;
use App\Services\Auth\DisabledUserException;
use App\Services\Auth\DiscordOAuthService;
use App\Services\Auth\OAuthConfigurationException;
use App\Services\Auth\OAuthProviderException;
use App\Services\Auth\UnverifiedProviderEmailException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiscordOAuthController extends Controller
{
    public function __construct(
        private readonly DiscordOAuthService $discordOAuth,
        private readonly ApiProviderHandoffService $handoffs,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);

        try {
            $authorizationUrl = $this->discordOAuth->authorizationUrl($state);
        } catch (OAuthConfigurationException) {
            return redirect()
                ->route('login')
                ->withErrors(['discord' => 'Discord sign-in is not configured.']);
        }

        $request->session()->put(DiscordOAuthService::STATE_SESSION_KEY, $state);

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        // See GoogleOAuthController::callback: a client application's handoff
        // comes back through the same registered redirect URI, and is answered by
        // returning the browser to the waiting application instead of opening a
        // session here (AUTH-020).
        $handoffReturnUrl = $this->handoffs->completeCallback(
            AuthIdentity::PROVIDER_DISCORD,
            $request->query('state') === null ? null : (string) $request->query('state'),
            $request->query('code') === null ? null : (string) $request->query('code'),
            $request->query('error') === null ? null : (string) $request->query('error'),
        );

        if ($handoffReturnUrl !== null) {
            return redirect()->away($handoffReturnUrl);
        }

        $expectedState = $request->session()->pull(DiscordOAuthService::STATE_SESSION_KEY);

        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->backToLoginWithError('Discord sign-in expired. Please try again.');
        }

        if ($request->query('error') !== null) {
            return $this->backToLoginWithError('Discord sign-in was canceled or denied.');
        }

        $code = $request->query('code');

        if (! is_string($code) || trim($code) === '') {
            return $this->backToLoginWithError('Discord sign-in could not be completed.');
        }

        try {
            $this->discordOAuth->authenticateFromCallbackCode($code);
        } catch (DisabledUserException) {
            return $this->backToLoginWithError('This account is disabled.');
        } catch (UnverifiedProviderEmailException) {
            return $this->backToLoginWithError('Discord did not return a verified email.');
        } catch (OAuthConfigurationException) {
            return $this->backToLoginWithError('Discord sign-in is not configured.');
        } catch (OAuthProviderException) {
            return $this->backToLoginWithError('Discord sign-in could not be completed.');
        }

        $request->session()->regenerate();

        return redirect()->intended(config('meridian.oauth.post_login_redirect'));
    }

    private function backToLoginWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['discord' => $message]);
    }
}
