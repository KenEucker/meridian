<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Training;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Training>
 */
class TrainingFactory extends Factory
{
    protected $model = Training::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'department_id' => null,
            'team_id' => null,
            'event_id' => null,
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'expires_after_days' => null,
            'archived_at' => null,
        ];
    }

    /**
     * A training that expires after the given number of days (e.g. annual).
     */
    public function expiresAfterDays(int $days = 365): static
    {
        return $this->state(fn (): array => [
            'expires_after_days' => $days,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
