<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'event_id' => null,
            'department_id' => null,
            'actor_user_id' => null,
            'actor_device_id' => null,
            'actor_node_id' => null,
            'action' => $this->faker->randomElement([
                'organization.status.changed',
                'permission.role.granted',
                'incident.status.changed',
            ]),
            'entity_type' => 'audit.test_entity',
            'entity_id' => (string) Str::uuid(),
            'before_json' => null,
            'after_json' => null,
            'reason' => null,
            'source_context' => AuditEvent::SOURCE_SYSTEM,
            'signature_metadata_json' => null,
        ];
    }
}
