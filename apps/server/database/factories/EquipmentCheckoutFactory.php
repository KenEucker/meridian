<?php

namespace Database\Factories;

use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentCheckout>
 */
class EquipmentCheckoutFactory extends Factory
{
    protected $model = EquipmentCheckout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_item_id' => EquipmentItem::factory()->checkedOut(),
            'event_id' => function (array $attributes): string {
                $item = EquipmentItem::query()->findOrFail($attributes['equipment_item_id']);

                return (string) ($item->event_id ?? Event::factory()
                    ->for($item->organization)
                    ->create()
                    ->id);
            },
            'staff_id' => Staff::factory(),
            'shift_id' => null,
            'quantity' => 1,
            'quantity_returned' => null,
            'checked_out_at' => now(),
            'checked_out_by_user_id' => User::factory(),
            'returned_at' => null,
            'returned_by_user_id' => null,
            'return_condition' => null,
        ];
    }

    public function returned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'returned_at' => now(),
            'returned_by_user_id' => User::factory(),
            'return_condition' => EquipmentItem::STATUS_RETURNED,
            'quantity_returned' => $attributes['quantity'] ?? 1,
        ]);
    }

    /** Units of a pool handed out on one checkout (EQUIP-011). */
    public function quantity(int $quantity): static
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
        ]);
    }
}
