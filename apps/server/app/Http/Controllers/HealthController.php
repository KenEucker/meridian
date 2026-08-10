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
            // (BRAND-003A, technical spec 25.3). Identifiers, plus the node's
            // own id and name since M18.61: a device refusing a foreign-node
            // workstation QR has to say which node *it* is using (AUTH-034),
            // and the machine label a technician gave this install is the only
            // name it has. Nothing else here is a name, and nothing is
            // configuration an unauthenticated caller could act on.
            'node_id' => $node?->getKey(),
            'node_name' => $node?->node_name,
            'node_role' => $node?->node_role,
            'organization_id' => $node?->organization_id,
            'event_id' => $node?->event_id,
        ]);
    }
}
