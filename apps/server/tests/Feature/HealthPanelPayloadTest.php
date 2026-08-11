<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\Organization;
use App\Models\SyncConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The technical spec 25.3 facts `GET /api/health` reports for the Electron
 * health panel (M19.5): event name, sync summary, offline read set
 * servability, and connected device count. `HealthEndpointTest` covers the
 * degraded no-database shape of the same payload.
 */
class HealthPanelPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_node_identity_and_event_name(): void
    {
        $organization = Organization::factory()->create();
        $event = Event::factory()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Signal Camp 2026',
        ]);
        Node::factory()->onsite()->create([
            'node_name' => 'onsite-command-1',
            'organization_id' => $organization->getKey(),
            'event_id' => $event->getKey(),
        ]);

        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('node_name', 'onsite-command-1');
        $response->assertJsonPath('node_role', 'onsite');
        $response->assertJsonPath('event_name', 'Signal Camp 2026');
    }

    public function test_event_name_is_null_when_the_node_is_not_locked_to_an_event(): void
    {
        Node::factory()->create(['event_id' => null]);

        $this->getJson('/api/health')->assertJsonPath('event_name', null);
    }

    public function test_sync_summary_reports_status_and_counts_without_detail_lines(): void
    {
        $node = Node::factory()->onsite()->create();
        NodeOperation::factory()->count(2)->create([
            'origin_node_id' => $node->getKey(),
            'status' => NodeOperation::STATUS_PENDING,
        ]);
        NodeOperation::factory()->create([
            'origin_node_id' => $node->getKey(),
            'status' => NodeOperation::STATUS_FAILED,
            'failure_reason' => 'central refused the exchange',
        ]);
        // The conflict's backing operation is pinned to this node: the factory
        // default would mint a second node, which would then be the active one.
        SyncConflict::factory()->open()->create([
            'operation_id' => NodeOperation::factory()->conflicted()->create([
                'origin_node_id' => $node->getKey(),
            ]),
        ]);

        $response = $this->getJson('/api/health');

        $response->assertJsonPath('sync.status', 'attention');
        $response->assertJsonPath('sync.queued', 2);
        $response->assertJsonPath('sync.undelivered', 1);
        $response->assertJsonPath('sync.open_conflicts', 1);
        // The failure and refusal detail lines carry operation types and
        // reasons; they stay behind God Mode and must not leak through an
        // unauthenticated liveness endpoint.
        $this->assertArrayNotHasKey('failures', $response->json('sync'));
        $this->assertArrayNotHasKey('refusals', $response->json('sync'));
        $this->assertStringNotContainsString('central refused the exchange', $response->getContent());
    }

    public function test_offline_read_set_reports_servable_and_event_mode_requirement(): void
    {
        Node::factory()->onsite()->create();
        // Event mode derives from the effective node role, which node config
        // resolution reads from file config or a database override rather than
        // from the node row a factory minted.
        config(['meridian.node.role' => Node::ROLE_ONSITE]);

        $response = $this->getJson('/api/health');

        // An onsite node is in event mode, and the test app registers the
        // offline read set route, so both halves are true here.
        $response->assertJsonPath('offline_read_set.required', true);
        $response->assertJsonPath('offline_read_set.servable', true);
    }

    public function test_connected_devices_counts_recently_seen_unrevoked_devices_only(): void
    {
        Node::factory()->create();
        Device::factory()->count(2)->create(['last_seen_at' => now()->subMinutes(2)]);
        Device::factory()->create([
            'last_seen_at' => now()->subMinutes(HealthController::CONNECTED_DEVICE_WINDOW_MINUTES + 5),
        ]);
        Device::factory()->revoked()->create(['last_seen_at' => now()]);

        $response = $this->getJson('/api/health');

        $response->assertJsonPath('connected_devices.count', 2);
        $response->assertJsonPath(
            'connected_devices.window_minutes',
            HealthController::CONNECTED_DEVICE_WINDOW_MINUTES,
        );
        // Device labels are names an unauthenticated caller has no business
        // reading; only the count travels.
        $this->assertArrayNotHasKey('devices', (array) $response->json('connected_devices'));
    }
}
