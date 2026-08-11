<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\SyncConflict;
use App\Services\EventMode\EventModeGuard;
use App\Services\Node\NodeSetupService;
use App\Services\Node\NodeSyncHealth;
use App\Services\Offline\OfflineReadSetProbe;
use Illuminate\Http\JsonResponse;
use Throwable;

class HealthController extends Controller
{
    /**
     * How recently a device must have talked to this node to count as
     * connected in the health panel. Devices stamp `last_seen_at` on
     * authenticated API traffic, so this is "devices actively working against
     * this node", not "devices that ever enrolled".
     */
    public const CONNECTED_DEVICE_WINDOW_MINUTES = 15;

    /**
     * Return a minimal health and version payload.
     *
     * This endpoint is intentionally unauthenticated and exposes only
     * non-sensitive status and version metadata so the on-site Electron
     * health panel can read server liveness and version state
     * (technical spec 25.3 and 26.3).
     */
    public function show(
        NodeSetupService $nodes,
        NodeSyncHealth $syncHealth,
        OfflineReadSetProbe $readSet,
        EventModeGuard $eventMode,
    ): JsonResponse {
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
            // The remaining technical spec 25.3 panel facts (M19.5). Same
            // defensive posture and the same disclosure rule as the identity
            // fields: status codes and counts only — no failure reasons, no
            // device labels, no conflict payloads. The event name is the one
            // human-readable string, and it is the name of the event this
            // install is locked to, which the kiosk shows on every screen
            // anyway.
            'event_name' => $this->eventName($node),
            'sync' => $this->syncSummary($node, $syncHealth),
            'offline_read_set' => $this->offlineReadSet($readSet, $eventMode),
            'connected_devices' => $this->connectedDevices(),
        ]);
    }

    private function eventName(?Node $node): ?string
    {
        if ($node?->event_id === null) {
            return null;
        }

        try {
            return Event::query()->find($node->event_id)?->name;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whitelisted projection of {@see NodeSyncHealth::describe()}: the status
     * and the numeric summaries, without the failure and refusal detail lines
     * that carry operation types and reasons (those stay behind God Mode).
     *
     * @return array<string, mixed>|null
     */
    private function syncSummary(?Node $node, NodeSyncHealth $syncHealth): ?array
    {
        try {
            $sync = $syncHealth->describe($node);

            return [
                'status' => $sync['status'],
                'status_label' => $sync['status_label'],
                'queued' => (int) $sync['queued'],
                'undelivered' => (int) $sync['undelivered'],
                'unapplied' => (int) $sync['unapplied'],
                'open_conflicts' => (int) SyncConflict::query()->open()->count(),
                'last_sent_at' => $sync['last_sent_at'],
                'last_received_at' => $sync['last_received_at'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{required: bool, servable: bool}|null
     */
    private function offlineReadSet(OfflineReadSetProbe $readSet, EventModeGuard $eventMode): ?array
    {
        try {
            return [
                'required' => $eventMode->isEventMode(),
                'servable' => $readSet->isAvailable(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{count: int, window_minutes: int}|null
     */
    private function connectedDevices(): ?array
    {
        try {
            return [
                'count' => (int) Device::query()
                    ->active()
                    ->where('last_seen_at', '>=', now()->subMinutes(self::CONNECTED_DEVICE_WINDOW_MINUTES))
                    ->count(),
                'window_minutes' => self::CONNECTED_DEVICE_WINDOW_MINUTES,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
