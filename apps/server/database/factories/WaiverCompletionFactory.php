<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaiverCompletion>
 */
class WaiverCompletionFactory extends Factory
{
    protected $model = WaiverCompletion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'waiver_id' => Waiver::factory(),
            'staff_id' => Staff::factory(),
            'completed_at' => now(),
            'expires_at' => null,
            'recorded_by_user_id' => User::factory(),
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
