<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => fn (array $attributes): string => Shift::query()->findOrFail($attributes['shift_id'])->event_id,
            'department_id' => fn (array $attributes): string => Shift::query()->findOrFail($attributes['shift_id'])->department_id,
            'shift_id' => Shift::factory(),
            'shift_assignment_id' => null,
            'staff_id' => fn (array $attributes): string => ShiftAssignment::query()
                ->where('shift_id', $attributes['shift_id'])
                ->value('staff_id')
                ?? Staff::factory()->create()->id,
            'current_state' => AttendanceRecord::STATE_CHECKED_IN,
            'checked_in_at' => now(),
            'checked_out_at' => null,
            'no_show_at' => null,
            'corrected_at' => null,
        ];
    }
}
