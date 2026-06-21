<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addWeek()->setTime(8, 0);
        $endsAt = $startsAt->copy()->addHours(8);

        return [
            'event_id' => Event::factory(),
            'department_id' => function (array $attributes): string {
                $event = Event::query()->findOrFail($attributes['event_id']);

                return Department::factory()
                    ->for($event->organization)
                    ->create()
                    ->id;
            },
            'eligible_team_id' => fn (array $attributes): string => Team::query()
                ->where('department_id', $attributes['department_id'])
                ->value('id')
                ?? Team::factory()->create(['department_id' => $attributes['department_id']])->id,
            'title' => $this->faker->jobTitle(),
            'department_name_snapshot' => null,
            'team_name_snapshot' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'capacity' => null,
            'cancelled_at' => null,
        ];
    }

    /**
     * A shift with a defined staff capacity.
     */
    public function withCapacity(int $capacity = 10): static
    {
        return $this->state(fn (): array => [
            'capacity' => $capacity,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'cancelled_at' => now(),
        ]);
    }
}
