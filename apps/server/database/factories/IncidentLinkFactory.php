<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentLink>
 */
class IncidentLinkFactory extends Factory
{
    protected $model = IncidentLink::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_incident_id' => Incident::factory(),
            'target_incident_id' => Incident::factory(),
            'link_type' => IncidentLink::TYPE_RELATED,
            'created_by_user_id' => User::factory(),
            'created_at' => now(),
            'unlinked_by_user_id' => null,
            'unlinked_at' => null,
        ];
    }

    public function forIncidents(Incident $source, Incident $target): static
    {
        return $this->state(fn (): array => [
            'source_incident_id' => $source->id,
            'target_incident_id' => $target->id,
        ]);
    }
}
