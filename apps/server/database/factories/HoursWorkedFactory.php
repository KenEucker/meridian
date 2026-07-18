<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\HoursWorked;
use App\Models\Shift;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HoursWorked>
 */
class HoursWorkedFactory extends Factory
{
    protected $model = HoursWorked::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actualStartedAt = now()->subHours(2)->startOfMinute();
        $actualEndedAt = $actualStartedAt->copy()->addHours(2);

        return [
            'shift_id' => Shift::factory(),
            'staff_id' => Staff::factory(),
            'event_id' => fn (array $attributes): string => Shift::query()->findOrFail($attributes['shift_id'])->event_id,
            'department_id' => fn (array $attributes): string => Shift::query()->findOrFail($attributes['shift_id'])->department_id,
            'attendance_record_id' => fn (array $attributes): string => AttendanceRecord::factory()->create([
                'shift_id' => $attributes['shift_id'],
                'staff_id' => $attributes['staff_id'],
                'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
                'checked_in_at' => $actualStartedAt,
                'checked_out_at' => $actualEndedAt,
            ])->id,
            'actual_started_at' => $actualStartedAt,
            'actual_ended_at' => $actualEndedAt,
            'minutes_worked' => 120,
            'status' => HoursWorked::STATUS_RECORDED,
            'corrected_by_user_id' => null,
            'server_corrected_at' => null,
            'frozen_at' => null,
        ];
    }
}
