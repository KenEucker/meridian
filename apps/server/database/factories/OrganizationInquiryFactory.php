<?php

namespace Database\Factories;

use App\Models\OrganizationInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationInquiry>
 */
class OrganizationInquiryFactory extends Factory
{
    protected $model = OrganizationInquiry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'contact_email' => Str::lower(fake()->unique()->safeEmail()),
            'description' => fake()->paragraph(),
            'status' => OrganizationInquiry::STATUS_NEW,
            'review_notes' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'submitted_at' => now(),
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationInquiry::STATUS_REVIEWED,
            'reviewed_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrganizationInquiry::STATUS_CLOSED,
            'reviewed_at' => now(),
        ]);
    }
}
