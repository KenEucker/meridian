<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftAssignment>
 */
class ShiftAssignmentFactory extends Factory
{
    protected $model = ShiftAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'staff_id' => Staff::factory(),
            'assigned_by_user_id' => null,
            'assignment_status' => ShiftAssignment::STATUS_SIGNED_UP,
            'removed_at' => null,
        ];
    }

    public function assignedByUser(): static
    {
        return $this->state(fn (): array => [
            'assignment_status' => ShiftAssignment::STATUS_ASSIGNED,
            'assigned_by_user_id' => \App\Models\User::factory(),
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (): array => [
            'removed_at' => now(),
        ]);
    }
}
