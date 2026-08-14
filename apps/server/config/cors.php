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
            //
            // The last two are the packaged mobile apps (technical spec 3.3,
            // 26.5). A Capacitor app serves its bundled client from a fixed
            // local origin — `https://localhost` on Android, `capacitor://localhost`
            // on iOS — and every call it makes to a node is therefore
            // cross-origin, exactly as the dev server's is. Without them an
            // installed app reaches its node and is refused by the browser
            // rather than by the node, which reads on the device as a node
            // that is simply unreachable.
            'http://127.0.0.1:5173,http://localhost:5173,http://127.0.0.1:5175,http://localhost:5175,https://localhost,capacitor://localhost',
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
