<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeHealthReport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NodeHealthReport>
 */
class NodeHealthReportFactory extends Factory
{
    protected $model = NodeHealthReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'report_uuid' => (string) Str::uuid(),
            'overall_status' => 'healthy',
            'node_name' => 'meridian-onsite',
            'node_role' => Node::ROLE_ONSITE,
            'meridian_version' => '0.0.0-test',
            'config_schema_version' => 1,
            'category_statuses_json' => ['application' => 'healthy'],
            'summary_json' => ['queued_operations' => 0],
            'warnings_json' => [],
            'generated_at' => now(),
            'received_at' => now(),
        ];
    }
}
