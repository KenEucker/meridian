<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'MERIDIAN_CORS_ALLOWED_ORIGINS',
            // 5173 is the shared client's dev server. 5175 is the shared-workstation
            // Kiosk `pnpm run kiosk:workstation` starts (M18.32), which runs on a
            // port of its own so it can sit beside a client already on 5173.
            'http://127.0.0.1:5173,http://localhost:5173,http://127.0.0.1:5175,http://localhost:5175',
        )),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The two-tier connectivity header (M18.52; technical spec 9.6). A browser
    // hides response headers from cross-origin JavaScript unless they are named
    // here, and the client's dev server is a different origin from the node it
    // talks to. A device that cannot read it treats the tier as unreported and
    // says nothing about central, so this is the difference between the client
    // reporting `central_unreachable` in development and staying silent.
    'exposed_headers' => [\App\Services\Node\CentralReachability::HEADER],

    'max_age' => 0,

    'supports_credentials' => false,

];
