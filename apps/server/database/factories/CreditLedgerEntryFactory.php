<?php

namespace Database\Factories;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPolicy;
use App\Models\HoursWorked;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLedgerEntry>
 */
class CreditLedgerEntryFactory extends Factory
{
    protected $model = CreditLedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hours_worked_id' => HoursWorked::factory(),
            'event_id' => fn (array $attributes): string => HoursWorked::query()
                ->findOrFail($attributes['hours_worked_id'])->event_id,
            'department_id' => fn (array $attributes): string => HoursWorked::query()
                ->findOrFail($attributes['hours_worked_id'])->department_id,
            'shift_id' => fn (array $attributes): string => HoursWorked::query()
                ->findOrFail($attributes['hours_worked_id'])->shift_id,
            'staff_id' => fn (array $attributes): string => HoursWorked::query()
                ->findOrFail($attributes['hours_worked_id'])->staff_id,
            'credit_policy_id' => CreditPolicy::factory(),
            'entry_type' => CreditLedgerEntry::ENTRY_TYPE_CALCULATED,
            'hours' => '2.00',
            'credits' => '2.00',
            'status' => CreditLedgerEntry::STATUS_FROZEN,
            'calculation_basis' => [],
            'created_by_user_id' => null,
            'frozen_at' => now(),
        ];
    }
}
