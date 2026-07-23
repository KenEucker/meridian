<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSignup>
 */
class TrainingSignupFactory extends Factory
{
    protected $model = TrainingSignup::class;

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
            'signed_up_at' => now(),
            'cancelled_at' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'cancelled_at' => now(),
        ]);
    }
}
