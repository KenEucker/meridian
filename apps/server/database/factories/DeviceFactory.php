<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstSeenAt = now();

        return [
            'device_label' => fake()->words(2, true),
            'platform' => fake()->randomElement(['browser', 'ios', 'android', 'electron']),
            'device_public_key' => base64_encode(random_bytes(32)),
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $firstSeenAt,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
