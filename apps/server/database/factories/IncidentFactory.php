<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'incident_number' => sprintf('INC-%04d-%06d', now()->year, fake()->unique()->numberBetween(1, 999999)),
            'status' => Incident::STATUS_OPEN,
            'priority_label' => Incident::PRIORITY_ROUTINE,
            'started_at' => now(),
            'title' => $this->faker->sentence(4),
            'location_name' => $this->faker->optional()->streetName(),
            'location_address' => $this->faker->optional()->address(),
            'location_details' => $this->faker->optional()->sentence(),
            'camp_id' => null,
            'map_location_id' => null,
            'created_by_user_id' => User::factory(),
            'closed_at' => null,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (): array => [
            'event_id' => $event->id,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => Incident::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }
}
