<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\LocalFieldFixture;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local-only Field command auth for Alpha 1 mobile upload QA (M9.8).
 *
 * When enabled, a shared Bearer token authenticates as the seeded local Field
 * fixture user. Session auth still wins when already present. Disabled outside
 * local/dev configuration so production never accepts the token.
 */
class AuthenticateLocalFieldApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return $next($request);
        }

        if (! (bool) config('meridian.local_field_api.enabled')) {
            abort(401, 'Unauthenticated.');
        }

        $configuredToken = (string) config('meridian.local_field_api.token');
        $providedToken = (string) $request->bearerToken();

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            abort(401, 'Unauthenticated.');
        }

        $user = $this->resolveFixtureUser();

        if ($user === null) {
            abort(
                503,
                'Local Field fixture user is missing. From apps/server run: php artisan meridian:seed-local-field-fixture && php artisan config:clear',
            );
        }

        Auth::login($user);

        return $next($request);
    }

    private function resolveFixtureUser(): ?User
    {
        $userId = (string) config('meridian.local_field_api.user_id', LocalFieldFixture::USER_ID);

        return User::query()->find($userId)
            ?? User::query()->where('email', LocalFieldFixture::USER_EMAIL)->first();
    }
}
