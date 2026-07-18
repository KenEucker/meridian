<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeConfigValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests cover node setup mechanics, not the M8.7 event-mode
        // fail-closed safeguards; those are covered by EventModeSetupFailClosedTest.
        config(['meridian.event_mode.enabled' => false]);
    }

    public function test_setup_screen_loads_when_no_node_exists(): void
    {
        $response = $this->get(route('setup.show'));

        $response->assertOk();
        $response->assertSee('Meridian Node Setup');
        $response->assertSee('Create node');
        $response->assertSee('Standalone');
        $response->assertSee('Onsite');
    }

    public function test_setup_creates_first_node_with_keys_and_database_config_values(): void
    {
        $response = $this->followingRedirects()->post(route('setup.store'), [
            'node_name' => 'juplaya.2027.onsite',
            'node_role' => Node::ROLE_ONSITE,
            'central_node_url' => 'https://central.example.org',
        ]);

        $response->assertOk();
        $response->assertSee('Node setup complete.');
        $response->assertSee('juplaya.2027.onsite');
        $response->assertSee(Node::ROLE_ONSITE);

        $this->assertDatabaseCount('nodes', 1);

        $node = Node::query()->firstOrFail();

        $this->assertSame('juplaya.2027.onsite', $node->node_name);
        $this->assertSame(Node::ROLE_ONSITE, $node->node_role);
        $this->assertSame('https://central.example.org', $node->central_node_url);
        $this->assertTrue($this->looksLikeGeneratedPublicKey($node->public_key));

        $this->assertDatabaseHas('node_config_values', [
            'node_id' => $node->id,
            'key' => 'node_name',
            'source' => NodeConfigValue::SOURCE_DATABASE,
        ]);

        $privateKey = NodeConfigValue::query()
            ->where('node_id', $node->id)
            ->where('key', 'node_private_key')
            ->firstOrFail();

        $this->assertSame(NodeConfigValue::SOURCE_DATABASE, $privateKey->source);
        $this->assertTrue($this->looksLikeGeneratedPrivateKey($privateKey->value_json));
    }

    public function test_setup_rejects_invalid_values_without_creating_node(): void
    {
        $response = $this->from(route('setup.show'))->post(route('setup.store'), [
            'node_name' => 'bad node name',
            'node_role' => 'field-office',
            'central_node_url' => 'not-a-url',
        ]);

        $response->assertRedirect(route('setup.show'));
        $response->assertSessionHasErrors(['node_name', 'node_role', 'central_node_url']);
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_setup_cannot_create_second_active_node(): void
    {
        Node::factory()->create([
            'node_name' => 'existing.central',
        ]);

        $response = $this->from(route('setup.show'))->post(route('setup.store'), [
            'node_name' => 'second.central',
            'node_role' => Node::ROLE_CENTRAL,
        ]);

        $response->assertRedirect(route('setup.show'));
        $response->assertSessionHasErrors(['setup']);
        $this->assertDatabaseCount('nodes', 1);
        $this->assertDatabaseMissing('nodes', [
            'node_name' => 'second.central',
        ]);
    }

    private function looksLikeGeneratedPublicKey(string $publicKey): bool
    {
        $decoded = base64_decode($publicKey, true);

        return str_contains($publicKey, 'BEGIN PUBLIC KEY')
            || ($decoded !== false && strlen($decoded) === 32);
    }

    private function looksLikeGeneratedPrivateKey(string $privateKey): bool
    {
        $decoded = base64_decode($privateKey, true);

        return str_contains($privateKey, 'BEGIN PRIVATE KEY')
            || ($decoded !== false && strlen($decoded) === 64);
    }
}
