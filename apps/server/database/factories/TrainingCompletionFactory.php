<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingCompletion>
 */
class TrainingCompletionFactory extends Factory
{
    protected $model = TrainingCompletion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_id' => Training::factory(),
            'staff_id' => Staff::factory(),
            'completed_at' => now(),
            'expires_at' => null,
            'recorded_by_user_id' => User::factory(),
            'origin_node_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'completed_at' => now()->subYear(),
            'expires_at' => now()->subDay(),
        ]);
    }
}
