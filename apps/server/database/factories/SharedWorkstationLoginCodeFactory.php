<?php

namespace Database\Factories;

use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedWorkstationLoginCode>
 */
class SharedWorkstationLoginCodeFactory extends Factory
{
    protected $model = SharedWorkstationLoginCode::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generatedAt = now();
        $eventId = fake()->uuid();

        return [
            'user_id' => User::factory(),
            'event_id' => $eventId,
            'shared_workstation_id' => SharedWorkstation::factory()->state([
                'event_id' => $eventId,
            ]),
            'code_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => SharedWorkstationLoginCode::expiresAtFrom($generatedAt),
            'generated_by_user_id' => User::factory(),
            'used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function forSharedWorkstation(SharedWorkstation $sharedWorkstation): static
    {
        return $this->state(fn (): array => [
            'event_id' => $sharedWorkstation->event_id,
            'shared_workstation_id' => $sharedWorkstation->id,
        ]);
    }

    public function expired(): static
    {
        return $this->state(function (): array {
            $generatedAt = now()->subWeeks(SharedWorkstationLoginCode::VALID_DURATION_WEEKS + 1);

            return [
                'expires_at' => SharedWorkstationLoginCode::expiresAtFrom($generatedAt),
            ];
        });
    }

    public function used(): static
    {
        return $this->state(fn (): array => [
            'used_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
