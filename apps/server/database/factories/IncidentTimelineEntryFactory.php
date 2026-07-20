<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentTimelineEntry>
 */
class IncidentTimelineEntryFactory extends Factory
{
    protected $model = IncidentTimelineEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'actor_user_id' => User::factory(),
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => $this->faker->paragraph(),
            'previous_value' => null,
            'new_value' => null,
            'reason' => null,
            'created_at' => now(),
            'stricken_at' => null,
            'stricken_reason' => null,
        ];
    }

    public function forIncident(Incident $incident): static
    {
        return $this->state(fn (): array => [
            'incident_id' => $incident->id,
        ]);
    }
}
