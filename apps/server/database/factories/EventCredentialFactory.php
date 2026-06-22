<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventCredential;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventCredential>
 */
class EventCredentialFactory extends Factory
{
    protected $model = EventCredential::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'staff_id' => Staff::factory(),
            'status' => EventCredential::STATUS_ELIGIBLE,
            'status_reason' => null,
            'changed_by_user_id' => null,
            'revoked_at' => null,
        ];
    }

    public function blocked(?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'status' => EventCredential::STATUS_BLOCKED,
            'status_reason' => $reason ?? 'no_signed_up_shifts',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => EventCredential::STATUS_REVOKED,
            'status_reason' => 'manual_revocation',
            'revoked_at' => now(),
        ]);
    }
}
