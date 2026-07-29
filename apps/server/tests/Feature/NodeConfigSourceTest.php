<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\User;
use App\Services\Node\NodeConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class NodeConfigSourceTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

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

    public function test_orchid_node_config_screen_updates_setup_fields(): void
    {
        config(['meridian.event_mode.enabled' => false]);

        $node = Node::factory()->create([
            'node_name' => 'local.old',
            'node_role' => Node::ROLE_DEVELOPMENT,
            'central_node_url' => null,
        ]);

        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.node.config' => true,
            ],
        ]);

        $response = $this->screen('platform.node.config')
            ->actingAs($user)
            ->withoutFollowingRedirects()
            ->method('save', [
                'node' => [
                    'node_name' => 'local.updated',
                    'node_role' => Node::ROLE_ONSITE,
                    'central_node_url' => 'https://central.example.org',
                ],
            ]);

        $response->assertRedirect(route('platform.node.config'));

        $node->refresh();

        $this->assertSame('local.updated', $node->node_name);
        $this->assertSame(Node::ROLE_ONSITE, $node->node_role);
        $this->assertSame('https://central.example.org', $node->central_node_url);

        $roleOverride = NodeConfigValue::query()
            ->where('node_id', $node->id)
            ->where('key', 'node_role')
            ->firstOrFail();
        $centralUrlOverride = NodeConfigValue::query()
            ->where('node_id', $node->id)
            ->where('key', 'central_node_url')
            ->firstOrFail();

        $this->assertSame(NodeConfigValue::SOURCE_DATABASE, $roleOverride->source);
        $this->assertSame(Node::ROLE_ONSITE, $roleOverride->value_json);
        $this->assertSame('https://central.example.org', $centralUrlOverride->value_json);
    }

    /**
     * An install with no node identity yet is configurable from the console,
     * not only from the first-run setup page (technical spec 7.1, 22.2).
     */
    public function test_orchid_node_config_screen_sets_up_the_first_node(): void
    {
        config(['meridian.event_mode.enabled' => false]);

        $user = $this->nodeConfigUser();

        $screen = $this->screen('platform.node.config')->actingAs($user)->display();
        $screen->assertOk();
        $screen->assertSee('Set up this node');

        $response = $this->screen('platform.node.config')
            ->actingAs($user)
            ->withoutFollowingRedirects()
            ->method('setUp', [
                'node' => [
                    'node_name' => 'godmode.standalone',
                    'node_role' => Node::ROLE_DEVELOPMENT,
                    'central_node_url' => null,
                ],
            ]);

        $response->assertRedirect(route('platform.node.config'));

        $node = Node::query()->where('node_name', 'godmode.standalone')->firstOrFail();

        $this->assertSame(Node::ROLE_DEVELOPMENT, $node->node_role);
        $this->assertTrue((bool) $node->is_local);
        $this->assertNotNull($node->public_key);

        // The console path produces the same stored configuration the setup
        // page does, including the signing keypair.
        foreach (['node_name', 'node_role', 'node_public_key', 'node_private_key'] as $key) {
            $value = NodeConfigValue::query()
                ->where('node_id', $node->id)
                ->where('key', $key)
                ->firstOrFail();

            $this->assertSame(NodeConfigValue::SOURCE_DATABASE, $value->source);
            $this->assertSame((string) $user->id, (string) $value->updated_by_user_id);
        }
    }

    public function test_orchid_node_setup_refuses_a_second_node(): void
    {
        config(['meridian.event_mode.enabled' => false]);

        Node::factory()->create([
            'node_name' => 'local.existing',
            'node_role' => Node::ROLE_DEVELOPMENT,
        ]);

        $this->screen('platform.node.config')
            ->actingAs($this->nodeConfigUser())
            ->withoutFollowingRedirects()
            ->method('setUp', [
                'node' => [
                    'node_name' => 'local.second',
                    'node_role' => Node::ROLE_DEVELOPMENT,
                    'central_node_url' => null,
                ],
            ]);

        $this->assertSame(0, Node::query()->where('node_name', 'local.second')->count());
    }

    /**
     * Setting up an event-mode role from the console fails closed the same way
     * the setup page does (technical spec 8.6, 26.2).
     */
    public function test_orchid_node_setup_fails_closed_for_an_event_role_that_is_not_ready(): void
    {
        config([
            'meridian.event_mode.enabled' => true,
            'meridian.event_mode.require_https' => true,
            'app.url' => 'http://insecure.example.org',
        ]);

        $this->screen('platform.node.config')
            ->actingAs($this->nodeConfigUser())
            ->withoutFollowingRedirects()
            ->method('setUp', [
                'node' => [
                    'node_name' => 'onsite.primary',
                    'node_role' => Node::ROLE_ONSITE,
                    'central_node_url' => null,
                ],
            ]);

        $this->assertSame(0, Node::query()->count());
    }

    private function nodeConfigUser(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.node.config' => true,
            ],
        ]);
    }
}
