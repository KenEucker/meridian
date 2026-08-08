<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Services\Offline\OfflineReadSetProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'meridian.event_mode.require_offline_read_set' => true,
        ]);
    }

    private function markReadSetServable(bool $available): void
    {
        $this->instance(OfflineReadSetProbe::class, new class($available) extends OfflineReadSetProbe
        {
            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        });
    }

    public function test_setup_fails_closed_for_an_event_role_when_a_check_fails(): void
    {
        config(['app.url' => 'http://onsite.example.org']);
        $this->markReadSetServable(false);

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
        $this->markReadSetServable(true);

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

    public function test_setup_completes_for_development_role_without_https_or_the_read_set(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->markReadSetServable(false);

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
