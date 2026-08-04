<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffProfileChangeRequest>
 */
class StaffProfileChangeRequestFactory extends Factory
{
    protected $model = StaffProfileChangeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'organization_id' => Organization::factory(),
            'requested_by_user_id' => User::factory(),
            'kind' => StaffProfileChangeRequest::KIND_PROFILE_PICTURE,
            'status' => StaffProfileChangeRequest::STATUS_PENDING,
            'previous_handle' => null,
            'requested_handle' => null,
            'pending_picture_path' => null,
            'pending_picture_mime_type' => null,
            'pending_picture_size_bytes' => null,
            'pending_picture_width' => null,
            'pending_picture_height' => null,
            'self_service' => false,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'decision_reason' => null,
        ];
    }
}
