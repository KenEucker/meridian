<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\SystemConfigOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemConfigOverride>
 */
class SystemConfigOverrideFactory extends Factory
{
    protected $model = SystemConfigOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'name' => 'APP_DEBUG',
            'type' => 'boolean',
            'value_json' => json_encode(false),
            'secret_value' => null,
            'is_secret' => false,
            'is_active' => true,
            'change_reason' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }
}
