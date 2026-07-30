<?php

namespace Database\Factories;

use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\SharedWorkstationSession;
use App\Models\User;
use App\Services\Auth\SharedWorkstationSessionKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedWorkstationSession>
 */
class SharedWorkstationSessionFactory extends Factory
{
    protected $model = SharedWorkstationSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now();

        return [
            'user_id' => User::factory(),
            'event_id' => fake()->uuid(),
            'shared_workstation_id' => SharedWorkstation::factory(),
            'login_code_id' => SharedWorkstationLoginCode::factory()->used(),
            'session_key_hash' => SharedWorkstationSessionKey::hash(SharedWorkstationSessionKey::generate()),
            'started_at' => $startedAt,
            'last_activity_at' => $startedAt,
            'ended_at' => null,
            'ended_reason' => null,
        ];
    }

    /**
     * A session whose key a test needs to present. The raw key is the caller's
     * to hold, for the same reason it is in production: only the hash is stored.
     */
    public function withKey(string $sessionKey): static
    {
        return $this->state(fn (): array => [
            'session_key_hash' => SharedWorkstationSessionKey::hash($sessionKey),
        ]);
    }

    /** Last activity long enough ago that the inactivity window has closed. */
    public function idle(): static
    {
        return $this->state(fn (): array => [
            'last_activity_at' => now()->subMinutes(SharedWorkstationSession::INACTIVITY_TIMEOUT_MINUTES + 1),
        ]);
    }

    public function ended(string $reason = SharedWorkstationSession::ENDED_SIGNED_OUT): static
    {
        return $this->state(fn (): array => [
            'ended_at' => now(),
            'ended_reason' => $reason,
        ]);
    }
}
