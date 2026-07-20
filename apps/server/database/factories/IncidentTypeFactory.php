<?php

namespace Database\Factories;

use App\Models\IncidentType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentType>
 */
class IncidentTypeFactory extends Factory
{
    protected $model = IncidentType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->randomElement([
                'Medical',
                'Safety',
                'Logistics',
                'Radio',
                'Weather',
            ]).'-'.fake()->unique()->numberBetween(1, 9999),
            'created_at' => now(),
            'archived_at' => null,
        ];
    }
}
