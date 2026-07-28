<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'code' => Str::upper(Str::slug($name, '-')),
            'description' => fake()->sentence(),
            'default_team_id' => null,
            'archived_at' => null,
            'branding_logo_attachment_id' => null,
            'branding_accent_color' => null,
            'branding_surface_color' => null,
            'branding_updated_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Accent and surface background pass the contrast rules against the
     * organization branding factory palette and against Meridian's defaults,
     * so a branded department fixture is usable without a bespoke palette.
     */
    public function branded(
        string $accent = '#1f5f4b',
        ?string $surface = '#eef6f2',
    ): static {
        return $this->state(fn (): array => [
            'branding_accent_color' => $accent,
            'branding_surface_color' => $surface,
            'branding_updated_at' => now(),
        ]);
    }
}
