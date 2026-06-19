<?php

namespace Database\Factories;

use App\Models\PermissionRole;
use App\Models\Team;
use App\Models\TeamGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamGrant>
 */
class TeamGrantFactory extends Factory
{
    protected $model = TeamGrant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'event_id' => null,
            'permission_role_id' => PermissionRole::factory(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
