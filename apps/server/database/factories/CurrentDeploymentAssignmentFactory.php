<?php

namespace Database\Factories;

use App\Models\CurrentDeploymentAssignment;
use App\Models\Deployment;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrentDeploymentAssignment>
 */
class CurrentDeploymentAssignmentFactory extends Factory
{
    protected $model = CurrentDeploymentAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'event_id' => function (array $attributes): string {
                $shift = Shift::query()->findOrFail($attributes['shift_id']);

                return (string) $shift->event_id;
            },
            'department_id' => function (array $attributes): string {
                $shift = Shift::query()->findOrFail($attributes['shift_id']);

                return (string) $shift->department_id;
            },
            'staff_id' => Staff::factory(),
            'deployment_id' => function (array $attributes): string {
                $shift = Shift::query()->findOrFail($attributes['shift_id']);

                return Deployment::factory()->create([
                    'event_id' => $shift->event_id,
                    'department_id' => $shift->department_id,
                ])->id;
            },
            'assigned_by_user_id' => User::factory(),
            'assigned_at' => now(),
        ];
    }
}
