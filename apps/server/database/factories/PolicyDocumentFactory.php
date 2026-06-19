<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PolicyDocument>
 */
class PolicyDocumentFactory extends Factory
{
    protected $model = PolicyDocument::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'organization_id' => Organization::factory(),
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => fn (array $attributes): string => (string) $attributes['organization_id'],
            'title' => $title,
            'slug' => Str::slug($title),
            'markdown_source' => '# '.$title."\n\n".fake()->paragraph(),
            'state' => PolicyDocument::STATE_DRAFT,
            'document_revision' => 1,
            'fragment_revision' => 0,
            'published_at' => null,
            'archived_at' => null,
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
        ];
    }

    public function organizationScoped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => (string) $attributes['organization_id'],
        ]);
    }

    public function departmentScoped(): static
    {
        return $this->state(function (): array {
            $department = Department::factory()->create();

            return [
                'organization_id' => $department->organization_id,
                'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
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
                'scope_type' => PolicyDocument::SCOPE_TEAM,
                'scope_id' => $team->id,
            ];
        });
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'state' => PolicyDocument::STATE_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'state' => PolicyDocument::STATE_ARCHIVED,
            'archived_at' => now(),
        ]);
    }
}
