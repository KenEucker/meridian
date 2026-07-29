<?php

use App\Support\ApiTokenExpiry;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Empty on purpose. Meridian client applications authenticate to the API
    | with a bearer token rather than a browser session cookie (AUTH-018,
    | technical spec 11.4, data/API 5.4), so that authentication does not depend
    | on a client being served same-origin by the node it talks to. Sanctum's
    | stateful-SPA cookie mode is the mechanism that assumption would reintroduce.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Also empty on purpose, and for the same reason. Sanctum's default is
    | `['web']`, which would let the God Mode console's browser session
    | authenticate an `auth:sanctum` route. With no fallback guards, the bearer
    | token on the request is the only thing that can authenticate the API.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | The node-configured token lifetime (AUTH-024). Issuance also stamps each
    | token's own `expires_at` from the same value, and the guard requires both
    | to pass, so lowering this setting expires already-issued tokens at their
    | next request instead of waiting for the lifetime they were stamped with.
    |
    */

    'expiration' => ApiTokenExpiry::minutes(),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Prefixing issued tokens lets secret-scanning services recognize a leaked
    | Meridian token in a repository or log aggregator and report it.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'mrdn_at_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Routes
    |--------------------------------------------------------------------------
    |
    | `sanctum/csrf-cookie` exists to bootstrap the stateful SPA cookie flow.
    | Meridian does not use that flow, so the route is not published.
    |
    */

    'routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Retained at the package defaults. These apply to the stateful flow only.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
