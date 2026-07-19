<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Deployment;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'department_id' => function (array $attributes): string {
                $event = Event::query()->findOrFail($attributes['event_id']);

                return Department::factory()
                    ->for($event->organization)
                    ->create()
                    ->id;
            },
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'location_details' => $this->faker->optional()->sentence(),
            'map_location_id' => null,
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
