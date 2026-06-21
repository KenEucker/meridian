<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\ShiftTrainingRequirement;
use App\Models\Training;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftTrainingRequirement>
 */
class ShiftTrainingRequirementFactory extends Factory
{
    protected $model = ShiftTrainingRequirement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'training_id' => Training::factory(),
        ];
    }
}
