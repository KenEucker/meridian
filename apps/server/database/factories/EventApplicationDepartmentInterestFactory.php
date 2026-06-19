<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\EventApplication;
use App\Models\EventApplicationDepartmentInterest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventApplicationDepartmentInterest>
 */
class EventApplicationDepartmentInterestFactory extends Factory
{
    protected $model = EventApplicationDepartmentInterest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_application_id' => EventApplication::factory(),
            'department_id' => Department::factory(),
        ];
    }
}
