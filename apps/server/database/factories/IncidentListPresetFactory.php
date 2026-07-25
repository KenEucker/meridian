<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\IncidentListPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentListPreset>
 */
class IncidentListPresetFactory extends Factory
{
    protected $model = IncidentListPreset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'filters' => [
                'search' => '',
                'state' => 'active',
                'priority' => 'all',
                'type' => 'all',
                'responder' => 'all',
                'started_from' => null,
                'started_to' => null,
                'sort' => 'updated',
                'direction' => 'desc',
            ],
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (): array => [
            'event_id' => $event->id,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
        ]);
    }
}
