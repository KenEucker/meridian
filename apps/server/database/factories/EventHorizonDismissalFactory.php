<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventHorizonDismissal;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventHorizonDismissal>
 */
class EventHorizonDismissalFactory extends Factory
{
    protected $model = EventHorizonDismissal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'event_id' => Event::factory(),
            'dismissed_at' => now(),
        ];
    }
}
