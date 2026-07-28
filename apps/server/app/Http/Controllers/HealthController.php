<?php

namespace App\Http\Controllers;

use App\Services\Node\NodeSetupService;
use Illuminate\Http\JsonResponse;
use Throwable;

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
    public function show(NodeSetupService $nodes): JsonResponse
    {
        // Node identity is read defensively. This is a liveness probe first:
        // it has to answer on an install whose database is unreachable or not
        // yet migrated, which is exactly when someone is checking it. A
        // failure here degrades the three identity fields to null rather than
        // turning the probe itself into a 500.
        try {
            $node = $nodes->activeNode();
        } catch (Throwable) {
            $node = null;
        }

        return response()->json([
            'status' => 'ok',
            'environment' => app()->environment(),
            'server_version' => config('meridian.version'),
            'config_schema_version' => config('meridian.config_schema_version'),
            'timestamp' => now()->toIso8601String(),
            // Node identity, so the desktop wrapper can tell whether it is
            // locked to an event and whose organization it is serving
            // (BRAND-003A, technical spec 25.3). Identifiers only: no names,
            // no configuration, nothing an unauthenticated caller could not
            // already infer from being able to reach this node at all.
            'node_role' => $node?->node_role,
            'organization_id' => $node?->organization_id,
            'event_id' => $node?->event_id,
        ]);
    }
}
