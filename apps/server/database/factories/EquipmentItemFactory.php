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
            'tracking' => EquipmentItem::TRACKING_INDIVIDUAL,
            'asset_tag' => $this->faker->optional()->bothify('EQ-####'),
            'serial_number' => $this->faker->optional()->bothify('SN-########'),
            'quantity_total' => 1,
            'status' => EquipmentItem::STATUS_AVAILABLE,
            'archived_at' => null,
        ];
    }

    /**
     * A quantity of interchangeable units of one kind (EQUIP-010).
     *
     * No asset tag and no serial number, because a pool has no per-unit
     * identifier for one to belong to.
     */
    public function pooled(int $quantityTotal = 10): static
    {
        return $this->state(fn (): array => [
            'tracking' => EquipmentItem::TRACKING_POOLED,
            'asset_tag' => null,
            'serial_number' => null,
            'quantity_total' => $quantityTotal,
            'status' => EquipmentItem::STATUS_AVAILABLE,
        ]);
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
