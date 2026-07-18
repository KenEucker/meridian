<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventModeSetupFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'meridian.event_mode.enabled' => null,
            'meridian.event_mode.require_https' => true,
            'meridian.event_mode.require_powersync' => true,
            'powersync.endpoint' => 'http://powersync.test',
            'powersync.liveness_path' => '/probes/liveness',
        ]);
    }

    public function test_setup_fails_closed_for_an_event_role_when_a_check_fails(): void
    {
        config(['app.url' => 'http://onsite.example.org']);
        Http::fake([
            'http://powersync.test/probes/liveness' => Http::response([], 503),
        ]);

        $response = $this->post('/setup', [
            'node_name' => 'onsite-node',
            'node_role' => Node::ROLE_ONSITE,
        ]);

        $response->assertRedirect(route('setup.show'));
        $response->assertSessionHasErrors('setup');
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_setup_completes_for_an_event_role_when_checks_pass(): void
    {
        config(['app.url' => 'https://onsite.example.org']);
        Http::fake([
            'http://powersync.test/probes/liveness' => Http::response(['status' => 'ok']),
        ]);

        $response = $this->post('/setup', [
            'node_name' => 'onsite-node',
            'node_role' => Node::ROLE_ONSITE,
        ]);

        $response->assertRedirect(route('setup.show'));
        $response->assertSessionHas('status', 'Node setup complete.');
        $this->assertDatabaseHas('nodes', [
            'node_name' => 'onsite-node',
            'node_role' => Node::ROLE_ONSITE,
        ]);
    }

    public function test_setup_completes_for_development_role_without_https_or_powersync(): void
    {
        config(['app.url' => 'http://localhost']);
        Http::fake([
            'http://powersync.test/probes/liveness' => Http::response([], 503),
        ]);

        $response = $this->post('/setup', [
            'node_name' => 'dev-node',
            'node_role' => Node::ROLE_DEVELOPMENT,
        ]);

        $response->assertRedirect(route('setup.show'));
        $response->assertSessionHas('status', 'Node setup complete.');
        $this->assertDatabaseHas('nodes', [
            'node_name' => 'dev-node',
            'node_role' => Node::ROLE_DEVELOPMENT,
        ]);
    }
}
