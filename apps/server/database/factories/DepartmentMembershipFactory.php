<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentMembership>
 */
class DepartmentMembershipFactory extends Factory
{
    protected $model = DepartmentMembership::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'staff_id' => Staff::factory(),
            'status' => DepartmentMembership::STATUS_ACTIVE,
            'status_reason' => null,
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
