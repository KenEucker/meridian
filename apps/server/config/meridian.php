<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Version
    |--------------------------------------------------------------------------
    |
    | The Meridian server build version. This is surfaced by the server health
    | endpoint so on-site Electron health panels can display the running server
    | version and warn on version mismatches (technical spec 25.3, 26.3).
    |
    */

    'version' => env('MERIDIAN_VERSION', '0.1.0-alpha'),

    /*
    |--------------------------------------------------------------------------
    | Configuration Schema Version
    |--------------------------------------------------------------------------
    |
    | The configuration schema version for this build (technical spec 26.3).
    | Config schema version mismatches do not block startup in Alpha 1, but the
    | value is exposed so clients and the Electron health panel can detect drift.
    |
    */

    'config_schema_version' => (int) env('MERIDIAN_CONFIG_SCHEMA_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Shared Vue Client
    |--------------------------------------------------------------------------
    |
    | Laravel serves the built shared Vue client at the product root while
    | Orchid remains under /admin. The default path points at apps/client/dist
    | in the monorepo; deployment packaging may override it.
    |
    */

    'client' => [
        'dist_path' => env('MERIDIAN_CLIENT_DIST_PATH', base_path('../client/dist')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Node Configuration
    |--------------------------------------------------------------------------
    |
    | Node configuration is file-first, database-second. These file-backed
    | values provide boot defaults; first-run setup and God mode may create
    | database overrides that are surfaced with their source.
    |
    */

    'node' => [
        'name' => env('MERIDIAN_NODE_NAME'),
        'role' => env('MERIDIAN_NODE_ROLE'),
        'public_key' => env('MERIDIAN_NODE_PUBLIC_KEY'),
        'private_key' => env('MERIDIAN_NODE_PRIVATE_KEY'),
        'central_node_url' => env('MERIDIAN_CENTRAL_NODE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Mode Safeguards
    |--------------------------------------------------------------------------
    |
    | Production/event mode must fail closed when required capabilities are
    | unavailable (technical spec 8.6 and 26.2). The server owns the HTTPS and
    | PowerSync checks; local encryption and device signing are client-side
    | checks. When `enabled` is null, event mode is derived from the effective
    | node role: any non-development role (standalone/central/onsite) is treated
    | as event/production mode (technical spec 26.1). Set MERIDIAN_EVENT_MODE to
    | force it on or off. The require_* flags default to on so a misconfigured
    | event node fails closed rather than silently starting insecurely.
    |
    */

    'event_mode' => [
        'enabled' => env('MERIDIAN_EVENT_MODE'),
        'require_https' => filter_var(env('MERIDIAN_EVENT_MODE_REQUIRE_HTTPS', true), FILTER_VALIDATE_BOOL),
        'require_powersync' => filter_var(env('MERIDIAN_EVENT_MODE_REQUIRE_POWERSYNC', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Magic Link Authentication
    |--------------------------------------------------------------------------
    |
    | Email magic links are the primary passwordless login path for Alpha 1
    | central authentication (technical spec section 11.1).
    |
    */

    'magic_link' => [
        'expires_minutes' => (int) env('MERIDIAN_MAGIC_LINK_EXPIRES_MINUTES', 15),
        'post_login_redirect' => env('MERIDIAN_MAGIC_LINK_REDIRECT', '/home'),
        'allow_account_creation' => filter_var(env('MERIDIAN_MAGIC_LINK_ALLOW_ACCOUNT_CREATION', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Authentication
    |--------------------------------------------------------------------------
    |
    | Google and Discord OAuth are Alpha 1 external-provider login paths
    | (technical spec section 11.1). Provider emails must be verified before
    | they resolve to Meridian user accounts.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Field Report Photos
    |--------------------------------------------------------------------------
    |
    | Short-lived signed URL lifetime for server-side Field Report photo
    | preview and download (technical spec 18.6; data/API 10.17).
    |
    */

    'field_report_photos' => [
        'signed_url_expires_minutes' => (int) env('MERIDIAN_FIELD_REPORT_PHOTO_SIGNED_URL_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Field API (development only)
    |--------------------------------------------------------------------------
    |
    | Enables Bearer-token auth for Field Report command uploads from the Vue
    | field app during local QA (M9.8). Keep disabled outside local development.
    | Seed identities with: php artisan meridian:seed-local-field-fixture
    |
    */

    'local_field_api' => [
        'enabled' => filter_var(env('MERIDIAN_LOCAL_FIELD_API_ENABLED', false), FILTER_VALIDATE_BOOL),
        'token' => env('MERIDIAN_LOCAL_FIELD_API_TOKEN'),
        'user_id' => env('MERIDIAN_LOCAL_FIELD_API_USER_ID', \App\Support\LocalFieldFixture::USER_ID),
    ],

    'oauth' => [
        'post_login_redirect' => env('MERIDIAN_OAUTH_REDIRECT', '/home'),

        'google' => [
            'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
            'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_OAUTH_REDIRECT_URI'),
            'authorize_url' => env('GOOGLE_OAUTH_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_url' => env('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            'userinfo_url' => env('GOOGLE_OAUTH_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
            'scopes' => ['openid', 'email', 'profile'],
        ],

        'discord' => [
            'client_id' => env('DISCORD_OAUTH_CLIENT_ID'),
            'client_secret' => env('DISCORD_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => env('DISCORD_OAUTH_REDIRECT_URI'),
            'authorize_url' => env('DISCORD_OAUTH_AUTHORIZE_URL', 'https://discord.com/oauth2/authorize'),
            'token_url' => env('DISCORD_OAUTH_TOKEN_URL', 'https://discord.com/api/oauth2/token'),
            'userinfo_url' => env('DISCORD_OAUTH_USERINFO_URL', 'https://discord.com/api/users/@me'),
            'scopes' => ['identify', 'email'],
        ],
    ],

];
