<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PowerSync Service
    |--------------------------------------------------------------------------
    |
    | PowerSync is the server-to-device sync layer. This configuration is the
    | Laravel integration boundary for later credential, upload, and readiness
    | work; Laravel remains the authority for domain-sensitive writes.
    |
    */

    'endpoint' => env('POWERSYNC_URL', 'http://127.0.0.1:8080'),
    'liveness_path' => env('POWERSYNC_LIVENESS_PATH', '/probes/liveness'),
    'request_timeout_seconds' => (float) env('POWERSYNC_REQUEST_TIMEOUT_SECONDS', 2),

];
