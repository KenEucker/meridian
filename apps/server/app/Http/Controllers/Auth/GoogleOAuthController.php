<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthIdentity;
use App\Services\Auth\ApiProviderHandoffService;
use App\Services\Auth\DisabledUserException;
use App\Services\Auth\GoogleOAuthService;
use App\Services\Auth\OAuthConfigurationException;
use App\Services\Auth\OAuthProviderException;
use App\Services\Auth\UnverifiedProviderEmailException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $googleOAuth,
        private readonly ApiProviderHandoffService $handoffs,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);

        try {
            $authorizationUrl = $this->googleOAuth->authorizationUrl($state);
        } catch (OAuthConfigurationException) {
            return redirect()
                ->route('login')
                ->withErrors(['google' => 'Google sign-in is not configured.']);
        }

        $request->session()->put(GoogleOAuthService::STATE_SESSION_KEY, $state);

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        // A client application's sign-in comes back through this same callback,
        // because a provider holds one registered redirect URI per node. The
        // state says which kind of sign-in this is: a handoff state is one this
        // node minted and stored, and it is answered by returning the browser to
        // the waiting application rather than by opening a session here
        // (AUTH-020).
        $handoffReturnUrl = $this->handoffs->completeCallback(
            AuthIdentity::PROVIDER_GOOGLE,
            $request->query('state') === null ? null : (string) $request->query('state'),
            $request->query('code') === null ? null : (string) $request->query('code'),
            $request->query('error') === null ? null : (string) $request->query('error'),
        );

        if ($handoffReturnUrl !== null) {
            return redirect()->away($handoffReturnUrl);
        }

        $expectedState = $request->session()->pull(GoogleOAuthService::STATE_SESSION_KEY);

        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->backToLoginWithError('Google sign-in expired. Please try again.');
        }

        if ($request->query('error') !== null) {
            return $this->backToLoginWithError('Google sign-in was canceled or denied.');
        }

        $code = $request->query('code');

        if (! is_string($code) || trim($code) === '') {
            return $this->backToLoginWithError('Google sign-in could not be completed.');
        }

        try {
            $this->googleOAuth->authenticateFromCallbackCode($code);
        } catch (DisabledUserException) {
            return $this->backToLoginWithError('This account is disabled.');
        } catch (UnverifiedProviderEmailException) {
            return $this->backToLoginWithError('Google did not return a verified email.');
        } catch (OAuthConfigurationException) {
            return $this->backToLoginWithError('Google sign-in is not configured.');
        } catch (OAuthProviderException) {
            return $this->backToLoginWithError('Google sign-in could not be completed.');
        }

        $request->session()->regenerate();

        return redirect()->intended(config('meridian.oauth.post_login_redirect'));
    }

    private function backToLoginWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['google' => $message]);
    }
}
