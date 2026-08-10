<?php

namespace Database\Factories;

use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationSignInRequest;
use App\Services\Auth\SharedWorkstationSignInPickupSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedWorkstationSignInRequest>
 */
class SharedWorkstationSignInRequestFactory extends Factory
{
    protected $model = SharedWorkstationSignInRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shared_workstation_id' => SharedWorkstation::factory(),
            'event_id' => fake()->uuid(),
            'purpose' => SharedWorkstationSignInRequest::PURPOSE_SIGN_IN,
            'shared_workstation_session_id' => null,
            'pickup_secret_hash' => SharedWorkstationSignInPickupSecret::hash(fake()->sha1()),
            'granted_by_user_id' => null,
            'granted_at' => null,
            'collected_at' => null,
            'expires_at' => now()->addMinutes(2),
        ];
    }
}
