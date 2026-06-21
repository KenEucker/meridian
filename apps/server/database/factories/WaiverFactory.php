<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Team;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Waiver>
 */
class WaiverFactory extends Factory
{
    protected $model = Waiver::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => fn (array $attributes): string => (string) $attributes['organization_id'],
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'expires_after_days' => null,
            'archived_at' => null,
        ];
    }

    /**
     * A waiver that expires after the given number of days.
     */
    public function expiresAfterDays(int $days = 365): static
    {
        return $this->state(fn (): array => [
            'expires_after_days' => $days,
        ]);
    }

    public function organizationScoped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => Waiver::SCOPE_ORGANIZATION,
            'scope_id' => (string) $attributes['organization_id'],
        ]);
    }

    public function departmentScoped(): static
    {
        return $this->state(function (): array {
            $department = Department::factory()->create();

            return [
                'organization_id' => $department->organization_id,
                'scope_type' => Waiver::SCOPE_DEPARTMENT,
                'scope_id' => $department->id,
            ];
        });
    }

    public function teamScoped(): static
    {
        return $this->state(function (): array {
            $team = Team::factory()->create();

            return [
                'organization_id' => $team->department->organization_id,
                'scope_type' => Waiver::SCOPE_TEAM,
                'scope_id' => $team->id,
            ];
        });
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
