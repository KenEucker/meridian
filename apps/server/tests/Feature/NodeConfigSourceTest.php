<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\User;
use App\Services\Node\NodeConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeConfigSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_reports_runtime_defaults_when_no_file_or_database_value_exists(): void
    {
        config([
            'meridian.node.name' => null,
            'meridian.node.role' => null,
        ]);

        $values = collect(app(NodeConfigResolver::class)->valuesFor(null))->keyBy('key');

        $this->assertSame(NodeConfigValue::SOURCE_RUNTIME, $values->get('node_role')['source']);
        $this->assertSame('runtime/default', $values->get('node_role')['source_label']);
        $this->assertSame(Node::ROLE_DEVELOPMENT, $values->get('node_role')['display_value']);
    }

    public function test_resolver_reports_file_config_source_when_file_value_exists(): void
    {
        config([
            'meridian.node.name' => 'file.central',
        ]);

        $values = collect(app(NodeConfigResolver::class)->valuesFor(null))->keyBy('key');

        $this->assertSame(NodeConfigValue::SOURCE_FILE, $values->get('node_name')['source']);
        $this->assertSame('file config', $values->get('node_name')['source_label']);
        $this->assertSame('file.central', $values->get('node_name')['display_value']);
    }

    public function test_database_overrides_win_over_file_config_and_hide_private_key_values(): void
    {
        config([
            'meridian.node.name' => 'file.central',
            'meridian.node.private_key' => 'file-secret',
        ]);

        $node = Node::factory()->create();

        NodeConfigValue::factory()->for($node)->create([
            'key' => 'node_name',
            'value_json' => 'database.central',
            'source' => NodeConfigValue::SOURCE_DATABASE,
        ]);

        NodeConfigValue::factory()->for($node)->create([
            'key' => 'node_private_key',
            'value_json' => 'database-secret',
            'source' => NodeConfigValue::SOURCE_DATABASE,
        ]);

        $values = collect(app(NodeConfigResolver::class)->valuesFor($node))->keyBy('key');

        $this->assertSame(NodeConfigValue::SOURCE_DATABASE, $values->get('node_name')['source']);
        $this->assertSame('database override', $values->get('node_name')['source_label']);
        $this->assertSame('database.central', $values->get('node_name')['display_value']);
        $this->assertSame('Configured (hidden)', $values->get('node_private_key')['display_value']);
    }

    public function test_orchid_node_config_screen_displays_sources_without_private_key_material(): void
    {
        $node = Node::factory()->create([
            'node_name' => 'godmode.central',
        ]);

        NodeConfigValue::factory()->for($node)->create([
            'key' => 'node_name',
            'value_json' => 'godmode.central',
            'source' => NodeConfigValue::SOURCE_DATABASE,
        ]);

        NodeConfigValue::factory()->for($node)->create([
            'key' => 'node_private_key',
            'value_json' => 'very-secret-private-key',
            'source' => NodeConfigValue::SOURCE_DATABASE,
        ]);

        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.node.config' => true,
            ],
        ]);

        $response = $this->actingAs($user)->get(route('platform.node.config'));

        $response->assertOk();
        $response->assertSee('Node Configuration');
        $response->assertSee('godmode.central');
        $response->assertSee('database override');
        $response->assertSee('Configured (hidden)');
        $response->assertDontSee('very-secret-private-key');
    }
}
