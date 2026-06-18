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
    ],

];
