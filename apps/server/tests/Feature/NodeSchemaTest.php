<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeConfigValue;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nodes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('nodes'));
        $this->assertTrue(Schema::hasColumn('nodes', 'node_name'));
        $this->assertTrue(Schema::hasColumn('nodes', 'node_role'));
        $this->assertTrue(Schema::hasColumn('nodes', 'public_key'));
        $this->assertTrue(Schema::hasColumn('nodes', 'organization_id'));
        $this->assertTrue(Schema::hasColumn('nodes', 'event_id'));
        $this->assertTrue(Schema::hasColumn('nodes', 'central_node_url'));
        $this->assertTrue(Schema::hasColumn('nodes', 'revoked_at'));
        $this->assertTrue(Schema::hasColumn('nodes', 'created_at'));
        $this->assertTrue(Schema::hasColumn('nodes', 'updated_at'));
    }

    public function test_node_config_values_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('node_config_values'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'node_id'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'key'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'value_json'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'source'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'updated_by_user_id'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'created_at'));
        $this->assertTrue(Schema::hasColumn('node_config_values', 'updated_at'));
    }

    public function test_node_has_many_config_values(): void
    {
        $node = Node::factory()->create();

        $nodeName = NodeConfigValue::factory()->for($node)->create([
            'key' => 'node_name',
            'value_json' => 'field.test',
        ]);

        $nodeRole = NodeConfigValue::factory()->for($node)->create([
            'key' => 'node_role',
            'value_json' => Node::ROLE_STANDALONE,
        ]);

        $node->refresh()->load('configValues');

        $this->assertCount(2, $node->configValues);
        $this->assertTrue($node->configValues->contains($nodeName));
        $this->assertTrue($node->configValues->contains($nodeRole));
    }

    public function test_node_config_value_belongs_to_node_and_updated_by_user(): void
    {
        $node = Node::factory()->create();
        $user = User::factory()->create();

        $value = NodeConfigValue::factory()->for($node)->create([
            'updated_by_user_id' => $user->id,
        ]);

        $this->assertTrue($value->node->is($node));
        $this->assertTrue($value->updatedByUser->is($user));
    }

    public function test_node_name_is_unique(): void
    {
        Node::factory()->create([
            'node_name' => 'juplaya.central',
        ]);

        $this->expectException(QueryException::class);

        Node::factory()->create([
            'node_name' => 'juplaya.central',
        ]);
    }

    public function test_active_scope_excludes_revoked_nodes(): void
    {
        $activeNode = Node::factory()->create();

        Node::factory()->create([
            'revoked_at' => now(),
        ]);

        $this->assertTrue(Node::query()->active()->first()->is($activeNode));
        $this->assertFalse($activeNode->isRevoked());
    }
}
