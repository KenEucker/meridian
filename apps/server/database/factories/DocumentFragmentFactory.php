<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentFragment>
 */
class DocumentFragmentFactory extends Factory
{
    protected $model = DocumentFragment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'organization_id' => Organization::factory(),
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => fn (array $attributes): string => (string) $attributes['organization_id'],
            'name' => $name,
            'slug' => Str::slug($name),
            'markdown_source' => '## '.$name."\n\n".fake()->paragraph(),
            'version' => 1,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function organizationScoped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => (string) $attributes['organization_id'],
        ]);
    }

    public function departmentScoped(): static
    {
        return $this->state(function (): array {
            $department = Department::factory()->create();

            return [
                'organization_id' => $department->organization_id,
                'scope_type' => DocumentFragment::SCOPE_DEPARTMENT,
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
                'scope_type' => DocumentFragment::SCOPE_TEAM,
                'scope_id' => $team->id,
            ];
        });
    }
}
