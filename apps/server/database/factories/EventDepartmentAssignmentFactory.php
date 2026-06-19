<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventDepartmentAssignment>
 */
class EventDepartmentAssignmentFactory extends Factory
{
    protected $model = EventDepartmentAssignment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'department_id' => Department::factory(),
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
