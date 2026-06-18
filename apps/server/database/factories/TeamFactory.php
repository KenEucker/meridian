<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'department_id' => Department::factory(),
            'name' => Str::title($name),
            'code' => Str::upper(Str::slug($name, '-')),
            'description' => fake()->sentence(),
            'is_default' => false,
            'archived_at' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Default',
            'code' => 'DEFAULT',
            'description' => null,
            'is_default' => true,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
