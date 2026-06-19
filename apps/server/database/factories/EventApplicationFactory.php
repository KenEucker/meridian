<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventApplication;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventApplication>
 */
class EventApplicationFactory extends Factory
{
    protected $model = EventApplication::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'organization_id' => function (array $attributes): string {
                $event = Event::find($attributes['event_id']);

                return $event?->organization_id ?? Organization::factory()->create()->id;
            },
            'staff_id' => null,
            'applicant_email' => Str::lower(fake()->unique()->safeEmail()),
            'applicant_legal_name' => fake()->name(),
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
            'decision_reason' => null,
            'withdrawn_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => EventApplication::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }
}
