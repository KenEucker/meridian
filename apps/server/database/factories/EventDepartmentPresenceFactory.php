<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Event;
use App\Models\EventDepartmentPresence;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventDepartmentPresence>
 */
class EventDepartmentPresenceFactory extends Factory
{
    protected $model = EventDepartmentPresence::class;

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
            'staff_id' => Staff::factory(),
            'current_state' => EventDepartmentPresence::STATE_OFF_SITE,
            'marked_on_site_at' => null,
            'marked_off_site_at' => now(),
            'last_marked_by_user_id' => User::factory(),
        ];
    }

    public function onSite(): static
    {
        return $this->state(fn (): array => [
            'current_state' => EventDepartmentPresence::STATE_ON_SITE,
            'marked_on_site_at' => now(),
            'marked_off_site_at' => null,
        ]);
    }
}
