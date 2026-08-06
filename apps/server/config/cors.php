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

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
