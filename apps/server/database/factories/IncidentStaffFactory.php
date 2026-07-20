<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentStaff;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentStaff>
 */
class IncidentStaffFactory extends Factory
{
    protected $model = IncidentStaff::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'staff_id' => Staff::factory(),
            'relationship_label' => 'Responder',
            'created_at' => now(),
        ];
    }
}
