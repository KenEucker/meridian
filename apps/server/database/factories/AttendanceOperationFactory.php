<?php

namespace Database\Factories;

use App\Models\AttendanceOperation;
use App\Models\AuditEvent;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttendanceOperation>
 */
class AttendanceOperationFactory extends Factory
{
    protected $model = AttendanceOperation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_uuid' => (string) Str::uuid(),
            'event_id' => fn (array $attributes): string => Shift::query()->findOrFail($attributes['shift_id'])->event_id,
            'department_id' => fn (array $attributes): string => Shift::query()->findOrFail($attributes['shift_id'])->department_id,
            'team_id' => fn (array $attributes): ?string => Shift::query()->findOrFail($attributes['shift_id'])->eligible_team_id,
            'shift_id' => Shift::factory(),
            'shift_assignment_id' => null,
            'staff_id' => fn (array $attributes): string => ShiftAssignment::query()
                ->where('shift_id', $attributes['shift_id'])
                ->value('staff_id')
                ?? Staff::factory()->create()->id,
            'operation_type' => AttendanceOperation::TYPE_CHECK_IN,
            'device_created_at' => now(),
            'server_received_at' => now(),
            'created_by_user_id' => User::factory(),
            'origin_device_id' => null,
            'origin_node_id' => null,
            'source_context' => AuditEvent::SOURCE_API,
        ];
    }
}
