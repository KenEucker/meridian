<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\SharedWorkstation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedWorkstation>
 */
class SharedWorkstationFactory extends Factory
{
    protected $model = SharedWorkstation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory()->state([
                'platform' => 'electron',
            ]),
            'event_id' => fake()->uuid(),
            'name' => fake()->unique()->slug(3),
            'trusted' => true,
            'revoked_at' => null,
        ];
    }

    public function untrusted(): static
    {
        return $this->state(fn (): array => [
            'trusted' => false,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
