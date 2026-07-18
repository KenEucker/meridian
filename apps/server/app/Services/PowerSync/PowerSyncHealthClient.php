<?php

namespace App\Services\PowerSync;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PowerSyncHealthClient
{
    /**
     * Check the PowerSync liveness probe without changing application state.
     */
    public function isAvailable(): bool
    {
        $endpoint = config('powersync.endpoint');

        if (! is_string($endpoint) || trim($endpoint) === '') {
            return false;
        }

        $path = (string) config('powersync.liveness_path', '/probes/liveness');
        $url = rtrim($endpoint, '/').'/'.ltrim($path, '/');

        try {
            return Http::timeout(
                (float) config('powersync.request_timeout_seconds', 2)
            )->get($url)->successful();
        } catch (ConnectionException) {
            return false;
        }
    }
}
