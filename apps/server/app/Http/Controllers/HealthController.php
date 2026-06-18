<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Return a minimal health and version payload.
     *
     * This endpoint is intentionally unauthenticated and exposes only
     * non-sensitive status and version metadata so the on-site Electron
     * health panel can read server liveness and version state
     * (technical spec 25.3 and 26.3).
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'environment' => app()->environment(),
            'server_version' => config('meridian.version'),
            'config_schema_version' => config('meridian.config_schema_version'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
