<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffOrganizationStatus>
 */
class StaffOrganizationStatusFactory extends Factory
{
    protected $model = StaffOrganizationStatus::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'staff_id' => Staff::factory(),
            'status' => StaffOrganizationStatus::STATUS_PROSPECTIVE,
            'status_reason' => null,
            'status_changed_at' => now(),
            'status_changed_by_user_id' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => StaffOrganizationStatus::STATUS_ACTIVE,
        ]);
    }
}
