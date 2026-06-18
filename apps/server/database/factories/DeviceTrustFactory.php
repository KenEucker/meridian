<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceTrust;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceTrust>
 */
class DeviceTrustFactory extends Factory
{
    protected $model = DeviceTrust::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstTrustedAt = now();

        return [
            'user_id' => User::factory(),
            'device_id' => Device::factory(),
            'trusted_node_fingerprint' => hash('sha256', fake()->uuid()),
            'first_trusted_at' => $firstTrustedAt,
            'last_seen_at' => $firstTrustedAt,
            'expires_at' => DeviceTrust::expiresAtFrom($firstTrustedAt),
            'revoked_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(function (): array {
            $firstTrustedAt = now()->subWeeks(DeviceTrust::TRUST_DURATION_WEEKS + 1);

            return [
                'first_trusted_at' => $firstTrustedAt,
                'last_seen_at' => $firstTrustedAt,
                'expires_at' => DeviceTrust::expiresAtFrom($firstTrustedAt),
            ];
        });
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
