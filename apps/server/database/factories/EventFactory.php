<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);
        $startsAt = now()->addMonths(4)->setTime(9, 0);
        $endsAt = $startsAt->copy()->addDays(4)->setTime(18, 0);

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => 'America/Los_Angeles',
            'minimum_staff_age' => null,
            'status' => null,
            'ic_department_id' => null,
            'active_event_window_starts_at' => $startsAt->copy()->subDay(),
            'active_event_window_ends_at' => $endsAt->copy()->addDay(),
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
