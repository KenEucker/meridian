<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeConfigValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeConfigValue>
 */
class NodeConfigValueFactory extends Factory
{
    protected $model = NodeConfigValue::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'key' => fake()->unique()->slug(2),
            'value_json' => fake()->word(),
            'source' => NodeConfigValue::SOURCE_DATABASE,
            'updated_by_user_id' => null,
        ];
    }
}
