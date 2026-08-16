<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * The framework's TrustProxies, reading its proxy list from Meridian
 * configuration (technical spec 8.2; deploy/runtipi/README.md).
 *
 * Behind a TLS-terminating proxy the stack does not own, Caddy speaks plain
 * HTTP and Laravel would generate http:// URLs on an https:// site unless the
 * forwarded scheme is believed. Reading `meridian.trusted_proxies` at request
 * time — rather than fixing the list in bootstrap/app.php — is what makes the
 * setting deployment configuration: it holds under `config:cache`, where a
 * bootstrap-time env() read would silently come up empty on a node whose
 * environment lives in a file.
 */
class TrustMeridianProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('meridian.trusted_proxies', ''));

        // Empty means no proxy is trusted and forwarded headers are ignored,
        // which is every deployment that terminates TLS in its own Caddy.
        return $configured === '' ? null : $configured;
    }
}
