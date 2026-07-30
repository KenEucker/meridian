<?php

use App\Support\ApiTokenExpiry;
use App\Support\RootPackageLicense;
use App\Support\RootPackageVersion;

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

    'version' => RootPackageVersion::resolve(base_path('../..')),

    /*
    |--------------------------------------------------------------------------
    | License
    |--------------------------------------------------------------------------
    |
    | The SPDX identifier Meridian is published under, read from the same root
    | manifest as the version. The God Mode console footer states this value so
    | it cannot claim a license the repository does not actually carry
    | (GOD-032, GOD-033).
    |
    */

    'license' => RootPackageLicense::resolve(base_path('../..')),

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
    | Meridian Admin Client
    |--------------------------------------------------------------------------
    |
    | Laravel serves the built Meridian Admin artifact at the product root while
    | Orchid remains under /admin as God Mode / repair tooling. The default path
    | points at apps/client/dist/admin in the monorepo; deployment packaging may
    | override it.
    |
    */

    'client' => [
        'dist_path' => env('MERIDIAN_CLIENT_DIST_PATH', base_path('../client/dist/admin')),
        'dev_server_url' => env('MERIDIAN_CLIENT_DEV_SERVER_URL', 'http://localhost:5173'),
        'use_dev_server' => filter_var(
            env('MERIDIAN_CLIENT_USE_DEV_SERVER', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator Documentation
    |--------------------------------------------------------------------------
    |
    | The God Mode Documentation page renders operator documentation packaged
    | with the deployment and never fetches it from a network service
    | (GOD-012, GOD-014). `corepack pnpm run docs:package` copies docs/operator/
    | here along with a manifest recording the Meridian version it was packaged
    | from, which the page shows beside the running build version (GOD-017).
    |
    | Only docs/operator/ is packaged, so specification, QA, planning, and issue
    | documents are not present to be served (GOD-015).
    |
    */

    'operator_docs' => [
        'path' => env('MERIDIAN_OPERATOR_DOCS_PATH', resource_path('operator-docs')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Changelog
    |--------------------------------------------------------------------------
    |
    | The God Mode Changelog page renders a data file generated at release build
    | time from repository history and packaged with the deployment, so it
    | renders completely without network access (GOD-021).
    |
    | Only the central node refreshes from the source repository, and refresh is
    | never required for the page to render (GOD-022 through GOD-025). The
    | source-repository token is read-only in scope, is never displayed in the
    | console, and is never written to logs (GOD-026).
    |
    */

    'changelog' => [
        'path' => env('MERIDIAN_CHANGELOG_PATH', resource_path('changelog/changelog.json')),

        'refresh' => [
            'enabled' => filter_var(env('MERIDIAN_CHANGELOG_REFRESH_ENABLED', true), FILTER_VALIDATE_BOOL),
            'repository' => env('MERIDIAN_CHANGELOG_REPOSITORY', 'KenEucker/meridian'),
            'api_url' => env('MERIDIAN_CHANGELOG_API_URL', 'https://api.github.com'),
            'token' => env('MERIDIAN_CHANGELOG_TOKEN'),
            'request_timeout_seconds' => (float) env('MERIDIAN_CHANGELOG_TIMEOUT_SECONDS', 5),
            'cache_minutes' => (int) env('MERIDIAN_CHANGELOG_CACHE_MINUTES', 60),
        ],
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

        // Pairing state (technical spec 7.3). These are normally written by
        // pairing as database overrides; the file values exist so a prepared
        // deployment can ship a known central identity.
        'central_node_name' => env('MERIDIAN_CENTRAL_NODE_NAME'),
        'central_node_public_key' => env('MERIDIAN_CENTRAL_NODE_PUBLIC_KEY'),
        'central_node_paired_url' => env('MERIDIAN_CENTRAL_NODE_PAIRED_URL'),
        'central_node_paired_at' => env('MERIDIAN_CENTRAL_NODE_PAIRED_AT'),

        'pairing' => [
            'request_timeout_seconds' => (float) env('MERIDIAN_NODE_PAIRING_TIMEOUT_SECONDS', 10),
        ],

        // Node-to-node sync (technical spec 10.1, 10.2). On-site pushes to
        // central continuously when internet exists and queues operations when
        // it does not, so the loop is scheduled rather than triggered by a
        // human. `batch_size` bounds one exchange and `max_exchanges_per_run`
        // bounds one run, which together let a backlog drain over repeated
        // exchanges without a single run spinning indefinitely.
        // `max_request_age_seconds` is the clock window in which a signed
        // exchange is accepted, and bounds replay of a captured request.
        'sync' => [
            'batch_size' => (int) env('MERIDIAN_NODE_SYNC_BATCH_SIZE', 100),
            'max_exchanges_per_run' => (int) env('MERIDIAN_NODE_SYNC_MAX_EXCHANGES_PER_RUN', 10),
            'request_timeout_seconds' => (float) env('MERIDIAN_NODE_SYNC_TIMEOUT_SECONDS', 15),
            'max_request_age_seconds' => (int) env('MERIDIAN_NODE_SYNC_MAX_REQUEST_AGE_SECONDS', 300),
            'schedule_enabled' => filter_var(
                env('MERIDIAN_NODE_SYNC_SCHEDULE_ENABLED', true),
                FILTER_VALIDATE_BOOL,
            ),
        ],
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
    | API Bearer Tokens
    |--------------------------------------------------------------------------
    |
    | Meridian client applications authenticate to the API with a Sanctum bearer
    | token (AUTH-018; technical spec 11.4; data/API 5.4). `expiration_minutes`
    | is the node-configured token lifetime required by AUTH-024; its documented
    | default is six weeks, matching the trusted-session window in technical
    | spec 11.2. It is independent of the 5-minute shared-workstation inactivity
    | timeout in technical spec 13.3.
    |
    | `login_code` covers the API magic-link login codes a client exchanges for
    | a token. `attempt_limit` bounds online guessing of a single issued code;
    | route throttling bounds the rate of requests and attempts.
    |
    | `provider_handoff` covers Google and Discord login completed in a system
    | browser (AUTH-020). `return_targets` is the registered return address per
    | client target: a client names the target it is, and the node resolves where
    | the browser is sent from its own configuration, so a request cannot
    | nominate a return address of its own. Blanking an entry disables provider
    | login for that client target. `expires_minutes` bounds the whole round
    | trip, from starting the handoff to exchanging its code for a token.
    |
    */

    'api_tokens' => [
        'expiration_minutes' => ApiTokenExpiry::minutes(),

        'default_client_name' => 'Meridian client',

        'login_code' => [
            'expires_minutes' => (int) env('MERIDIAN_API_LOGIN_CODE_EXPIRES_MINUTES', 15),
            'attempt_limit' => (int) env('MERIDIAN_API_LOGIN_CODE_ATTEMPT_LIMIT', 5),
        ],

        'provider_handoff' => [
            'expires_minutes' => (int) env('MERIDIAN_API_HANDOFF_EXPIRES_MINUTES', 10),

            'return_targets' => [
                'web' => env('MERIDIAN_API_HANDOFF_RETURN_WEB', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/login/handoff'),
                'mobile' => env('MERIDIAN_API_HANDOFF_RETURN_MOBILE', 'org.meridian.field://auth/handoff'),
                'desktop' => env('MERIDIAN_API_HANDOFF_RETURN_DESKTOP', 'org.meridian.kiosk://auth/handoff'),
            ],
        ],
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
