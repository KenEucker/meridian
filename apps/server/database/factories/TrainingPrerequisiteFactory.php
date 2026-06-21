<?php

namespace Database\Factories;

use App\Models\Training;
use App\Models\TrainingPrerequisite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingPrerequisite>
 */
class TrainingPrerequisiteFactory extends Factory
{
    protected $model = TrainingPrerequisite::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_id' => Training::factory(),
            'prerequisite_training_id' => Training::factory(),
        ];
    }
}
