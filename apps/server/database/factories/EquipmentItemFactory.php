<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentItem>
 */
class EquipmentItemFactory extends Factory
{
    protected $model = EquipmentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'event_id' => null,
            'department_id' => null,
            'name' => $this->faker->randomElement(['Radio', 'Safety Vest', 'Flag']).' '.$this->faker->bothify('##'),
            'asset_tag' => $this->faker->optional()->bothify('EQ-####'),
            'serial_number' => $this->faker->optional()->bothify('SN-########'),
            'status' => EquipmentItem::STATUS_AVAILABLE,
            'archived_at' => null,
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
        ]);
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $department->organization_id,
            'department_id' => $department->id,
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn (): array => [
            'status' => EquipmentItem::STATUS_RETURNED,
        ]);
    }

    public function checkedOut(): static
    {
        return $this->state(fn (): array => [
            'status' => EquipmentItem::STATUS_CHECKED_OUT,
        ]);
    }

    public function missing(): static
    {
        return $this->state(fn (): array => [
            'status' => EquipmentItem::STATUS_MISSING,
        ]);
    }

    public function damaged(): static
    {
        return $this->state(fn (): array => [
            'status' => EquipmentItem::STATUS_DAMAGED,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }
}
