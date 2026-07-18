<?php

namespace App\Services\Attendance;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\HoursWorked;
use App\Models\Node;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connected/synced check-out command for scheduled staff (SLB-004 through
 * SLB-006). Corrections, freezing, credits, and exports remain with later M10
 * tasks.
 */
class AttendanceCheckOutService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws AttendanceCheckOutException
     */
    public function checkOut(
        Shift $shift,
        Staff $staff,
        User $actor,
        string $operationUuid,
        ?Carbon $actualStartedAt = null,
        ?Carbon $actualEndedAt = null,
        ?Carbon $deviceCreatedAt = null,
        ?Carbon $serverReceivedAt = null,
        ?Device $originDevice = null,
        ?Node $originNode = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): AttendanceCheckOutResult {
        if (! Str::isUuid($operationUuid)) {
            throw AttendanceCheckOutException::invalidOperationUuid();
        }

        $serverReceivedAt ??= Carbon::now();
        $deviceCreatedAt ??= $serverReceivedAt->copy();
        $actualEndedAt ??= $deviceCreatedAt->copy();

        return DB::transaction(function () use (
            $shift,
            $staff,
            $actor,
            $operationUuid,
            $actualStartedAt,
            $actualEndedAt,
            $deviceCreatedAt,
            $serverReceivedAt,
            $originDevice,
            $originNode,
            $sourceContext,
        ): AttendanceCheckOutResult {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canCheckOutForShift($actor, $shift)) {
                throw AttendanceCheckOutException::unauthorized();
            }

            if ($shift->isCancelled()) {
                throw AttendanceCheckOutException::cancelledShift();
            }

            $assignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw AttendanceCheckOutException::noActiveAssignment();
            }

            $existingOperation = AttendanceOperation::query()
                ->where('operation_uuid', $operationUuid)
                ->lockForUpdate()
                ->first();

            if ($existingOperation !== null) {
                return $this->acceptedExisting($existingOperation, $shift, $staff, $assignment, $actor);
            }

            $record = AttendanceRecord::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($record !== null && $record->current_state === AttendanceRecord::STATE_CHECKED_OUT) {
                throw AttendanceCheckOutException::alreadyCheckedOut();
            }

            $actualStart = $actualStartedAt ?? $record?->checked_in_at;

            if ($actualStart === null) {
                throw AttendanceCheckOutException::missingActualStart();
            }

            if (! $actualEndedAt->greaterThan($actualStart)) {
                throw AttendanceCheckOutException::invalidActualTimeRange();
            }

            if ($record !== null && $record->hoursWorked()->exists()) {
                throw AttendanceCheckOutException::alreadyCheckedOut();
            }

            $operation = AttendanceOperation::query()->create([
                'operation_uuid' => $operationUuid,
                'event_id' => $shift->event_id,
                'department_id' => $shift->department_id,
                'team_id' => $shift->eligible_team_id,
                'shift_id' => $shift->id,
                'shift_assignment_id' => $assignment->id,
                'staff_id' => $staff->id,
                'operation_type' => AttendanceOperation::TYPE_CHECK_OUT,
                'device_created_at' => $deviceCreatedAt,
                'server_received_at' => $serverReceivedAt,
                'created_by_user_id' => $actor->id,
                'origin_device_id' => $originDevice?->id,
                'origin_node_id' => $originNode?->id,
                'source_context' => $sourceContext,
                'created_at' => $serverReceivedAt,
            ]);

            $createdStateChange = $record === null || $record->current_state !== AttendanceRecord::STATE_CHECKED_OUT;

            if ($record === null) {
                $record = AttendanceRecord::query()->create([
                    'event_id' => $shift->event_id,
                    'department_id' => $shift->department_id,
                    'shift_id' => $shift->id,
                    'shift_assignment_id' => $assignment->id,
                    'staff_id' => $staff->id,
                    'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
                    'checked_in_at' => $actualStart,
                    'checked_out_at' => $actualEndedAt,
                    'no_show_at' => null,
                    'corrected_at' => null,
                ]);
            } else {
                $record->forceFill([
                    'shift_assignment_id' => $assignment->id,
                    'current_state' => AttendanceRecord::STATE_CHECKED_OUT,
                    'checked_in_at' => $actualStart,
                    'checked_out_at' => $actualEndedAt,
                    'no_show_at' => null,
                    'corrected_at' => null,
                ])->save();
            }

            $hoursWorked = HoursWorked::query()->create([
                'event_id' => $shift->event_id,
                'department_id' => $shift->department_id,
                'shift_id' => $shift->id,
                'staff_id' => $staff->id,
                'attendance_record_id' => $record->id,
                'actual_started_at' => $actualStart,
                'actual_ended_at' => $actualEndedAt,
                'minutes_worked' => (int) $actualStart->diffInMinutes($actualEndedAt),
                'status' => HoursWorked::STATUS_RECORDED,
                'corrected_by_user_id' => null,
                'server_corrected_at' => null,
                'frozen_at' => null,
            ]);

            $this->audit->recordForEntity(
                entity: $operation,
                action: 'attendance.checked_out',
                actorUser: $actor,
                actorDevice: $originDevice,
                actorNode: $originNode,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($operation, $record->refresh(), $hoursWorked->refresh(), $createdStateChange),
                sourceContext: $sourceContext,
            );

            return new AttendanceCheckOutResult(
                operation: $operation->refresh(),
                record: $record->refresh(),
                hoursWorked: $hoursWorked->refresh(),
                createdStateChange: $createdStateChange,
                createdHours: true,
            );
        });
    }

    /**
     * @throws AttendanceCheckOutException
     */
    private function acceptedExisting(
        AttendanceOperation $operation,
        Shift $shift,
        Staff $staff,
        ShiftAssignment $assignment,
        User $actor,
    ): AttendanceCheckOutResult {
        $sameOperation = $operation->operation_type === AttendanceOperation::TYPE_CHECK_OUT
            && (string) $operation->shift_id === (string) $shift->id
            && (string) $operation->shift_assignment_id === (string) $assignment->id
            && (string) $operation->staff_id === (string) $staff->id
            && (string) $operation->created_by_user_id === (string) $actor->id;

        if (! $sameOperation) {
            throw AttendanceCheckOutException::operationUuidConflict();
        }

        $record = AttendanceRecord::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->first();

        $hoursWorked = $record?->hoursWorked;

        if ($record === null || $hoursWorked === null) {
            throw AttendanceCheckOutException::operationUuidConflict();
        }

        return new AttendanceCheckOutResult(
            operation: $operation,
            record: $record,
            hoursWorked: $hoursWorked,
            createdStateChange: false,
            createdHours: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(
        AttendanceOperation $operation,
        AttendanceRecord $record,
        HoursWorked $hoursWorked,
        bool $createdStateChange,
    ): array {
        return [
            'operation_uuid' => $operation->operation_uuid,
            'operation_type' => $operation->operation_type,
            'attendance_record_id' => $record->id,
            'hours_worked_id' => $hoursWorked->id,
            'event_id' => $operation->event_id,
            'department_id' => $operation->department_id,
            'team_id' => $operation->team_id,
            'shift_id' => $operation->shift_id,
            'shift_assignment_id' => $operation->shift_assignment_id,
            'staff_id' => $operation->staff_id,
            'device_created_at' => $operation->device_created_at?->toIso8601String(),
            'server_received_at' => $operation->server_received_at?->toIso8601String(),
            'current_state' => $record->current_state,
            'checked_in_at' => $record->checked_in_at?->toIso8601String(),
            'checked_out_at' => $record->checked_out_at?->toIso8601String(),
            'actual_started_at' => $hoursWorked->actual_started_at?->toIso8601String(),
            'actual_ended_at' => $hoursWorked->actual_ended_at?->toIso8601String(),
            'minutes_worked' => $hoursWorked->minutes_worked,
            'hours_status' => $hoursWorked->status,
            'created_state_change' => $createdStateChange,
        ];
    }
}
