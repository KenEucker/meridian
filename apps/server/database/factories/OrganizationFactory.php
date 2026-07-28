<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'default_ic_department_id' => null,
            'default_credit_policy_id' => null,
            'active_inactive_threshold_years' => 2,
            'prospective_inactive_threshold_years' => 1,
            'calendar_year_start_month' => 1,
            'calendar_year_start_day' => 1,
            'archived_at' => null,
            'branding_display_name' => null,
            'branding_palette_json' => null,
            'branding_full_lockup_attachment_id' => null,
            'branding_compact_mark_attachment_id' => null,
            'department_branding_enabled' => true,
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
     * @param  array<string, string>|null  $palette
     */
    public function branded(string $displayName = 'Deep Harbor Collective', ?array $palette = null): static
    {
        return $this->state(fn (): array => [
            'branding_display_name' => $displayName,
            'branding_palette_json' => $palette ?? [
                'primary' => '#123a5c',
                'secondary' => '#1f5f4b',
                'tertiary' => '#6b4f8a',
                'accent' => '#8c2f39',
                'canvas' => '#eef2f6',
                'surface' => '#ffffff',
                'foreground' => '#101418',
                'muted_foreground' => '#565f68',
                'border' => '#7c858d',
                'focus' => '#1b4f8f',
            ],
            'branding_updated_at' => now(),
        ]);
    }

    public function withDepartmentBrandingDisabled(): static
    {
        return $this->state(fn (): array => [
            'department_branding_enabled' => false,
        ]);
    }
}
