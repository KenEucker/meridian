<?php

namespace Database\Factories;

use App\Models\DepartmentMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMembership>
 */
class TeamMembershipFactory extends Factory
{
    protected $model = TeamMembership::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departmentMembership = DepartmentMembership::factory()->create();

        return [
            'team_id' => Team::factory()->for($departmentMembership->department)->create()->id,
            'staff_id' => $departmentMembership->staff_id,
            'department_membership_id' => $departmentMembership->id,
            'membership_role' => 'member',
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
