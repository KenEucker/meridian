<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\ShiftWaiverRequirement;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftWaiverRequirement>
 */
class ShiftWaiverRequirementFactory extends Factory
{
    protected $model = ShiftWaiverRequirement::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'waiver_id' => Waiver::factory(),
        ];
    }
}
