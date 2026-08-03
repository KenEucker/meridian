<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamDesignation;
use App\Models\TeamGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamDesignation>
 */
class TeamDesignationFactory extends Factory
{
    protected $model = TeamDesignation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = Team::factory();

        return [
            'organization_id' => Organization::factory(),
            'department_id' => null,
            'function_code' => TeamDesignation::FUNCTION_LOGISTICS,
            'team_id' => $team,
            'team_grant_id' => TeamGrant::factory(),
            'removed_at' => null,
        ];
    }

    public function removed(): static
    {
        return $this->state(fn (): array => [
            'removed_at' => now(),
        ]);
    }
}
