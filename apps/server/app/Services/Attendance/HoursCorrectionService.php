<?php

namespace App\Services\Attendance;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\HoursWorked;
use App\Models\Node;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrects actual hours while the correction window is open and freezes records
 * once that grace period has closed (HOURS-007, HOURS-008; SLB-007, SLB-031,
 * SLB-032).
 *
 * A correction is an append to the attendance history rather than an edit of
 * it (SLB-032). The `correct` attendance operation is written alongside the
 * changed record, and the audit entry carries the actual times, minutes, and
 * attendance timestamps as they stood before the change as well as after, so a
 * corrected record shows what it was as well as what it became. Nothing here
 * deletes or rewrites an earlier operation.
 *
 * Reachable from `POST /api/commands/correct-hours` since M18.4; until then it
 * had no route, and the only correction anybody could make was through a
 * tinker session.
 */
class HoursCorrectionService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws HoursCorrectionException
     */
    public function correctHours(
        HoursWorked $hoursWorked,
        User $actor,
        string $operationUuid,
        Carbon $actualStartedAt,
        Carbon $actualEndedAt,
        ?Carbon $deviceCreatedAt = null,
        ?Carbon $serverReceivedAt = null,
        ?Device $originDevice = null,
        ?Node $originNode = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): HoursCorrectionResult {
        if (! Str::isUuid($operationUuid)) {
            throw HoursCorrectionException::invalidOperationUuid();
        }

        if (! $actualEndedAt->greaterThan($actualStartedAt)) {
            throw HoursCorrectionException::invalidActualTimeRange();
        }

        $serverReceivedAt ??= Carbon::now();
        $deviceCreatedAt ??= $serverReceivedAt->copy();

        return DB::transaction(function () use (
            $hoursWorked,
            $actor,
            $operationUuid,
            $actualStartedAt,
            $actualEndedAt,
            $deviceCreatedAt,
            $serverReceivedAt,
            $originDevice,
            $originNode,
            $sourceContext,
        ): HoursCorrectionResult {
            $hoursWorked = HoursWorked::query()
                ->with(['attendanceRecord', 'event', 'shift.event'])
                ->whereKey($hoursWorked->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $shift = $hoursWorked->shift;

            if ($shift === null || ! $this->access->canCheckOutForShift($actor, $shift)) {
                throw HoursCorrectionException::unauthorized();
            }

            if ($hoursWorked->frozen_at !== null) {
                throw HoursCorrectionException::frozenHours(
                    $hoursWorked->frozen_at,
                    $hoursWorked->event?->timezone,
                );
            }

            $assignmentId = $hoursWorked->attendanceRecord?->shift_assignment_id;
            $existingOperation = AttendanceOperation::query()
                ->where('operation_uuid', $operationUuid)
                ->lockForUpdate()
                ->first();

            if ($existingOperation !== null) {
                return $this->acceptedExisting($existingOperation, $hoursWorked, $actor, $assignmentId);
            }

            $before = $this->auditSnapshot($hoursWorked, $hoursWorked->attendanceRecord);

            $operation = AttendanceOperation::query()->create([
                'operation_uuid' => $operationUuid,
                'event_id' => $hoursWorked->event_id,
                'department_id' => $hoursWorked->department_id,
                'team_id' => $shift->eligible_team_id,
                'shift_id' => $hoursWorked->shift_id,
                'shift_assignment_id' => $assignmentId,
                'staff_id' => $hoursWorked->staff_id,
                'operation_type' => AttendanceOperation::TYPE_CORRECT,
                'device_created_at' => $deviceCreatedAt,
                'server_received_at' => $serverReceivedAt,
                'created_by_user_id' => $actor->id,
                'origin_device_id' => $originDevice?->id,
                'origin_node_id' => $originNode?->id,
                'source_context' => $sourceContext,
                'created_at' => $serverReceivedAt,
            ]);

            $record = $hoursWorked->attendanceRecord;
            $record?->forceFill([
                'checked_in_at' => $actualStartedAt,
                'checked_out_at' => $actualEndedAt,
                'corrected_at' => $serverReceivedAt,
            ])->save();

            $minutesWorked = (int) $actualStartedAt->diffInMinutes($actualEndedAt);

            $hoursWorked->forceFill([
                'actual_started_at' => $actualStartedAt,
                'actual_ended_at' => $actualEndedAt,
                'minutes_worked' => $minutesWorked,
                'corrected_by_user_id' => $actor->id,
                'server_corrected_at' => $serverReceivedAt,
            ])->save();

            $hoursWorked = $hoursWorked->refresh()->load(['attendanceRecord', 'event', 'shift']);

            $this->audit->recordForEntity(
                entity: $hoursWorked,
                action: 'hours.corrected',
                actorUser: $actor,
                actorDevice: $originDevice,
                actorNode: $originNode,
                organizationId: $hoursWorked->event?->organization_id,
                eventId: $hoursWorked->event_id,
                departmentId: $hoursWorked->department_id,
                before: $before,
                after: $this->auditSnapshot($hoursWorked, $hoursWorked->attendanceRecord, $operation),
                sourceContext: $sourceContext,
            );

            return new HoursCorrectionResult(
                operation: $operation->refresh(),
                record: $hoursWorked->attendanceRecord,
                hoursWorked: $hoursWorked,
                createdCorrection: true,
            );
        });
    }

    /**
     * @throws HoursCorrectionException
     */
    public function freezeHours(
        HoursWorked $hoursWorked,
        User $actor,
        ?Carbon $frozenAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): HoursWorked {
        $frozenAt ??= Carbon::now();

        return DB::transaction(function () use ($hoursWorked, $actor, $frozenAt, $sourceContext): HoursWorked {
            $hoursWorked = HoursWorked::query()
                ->with(['attendanceRecord', 'event', 'shift.event'])
                ->whereKey($hoursWorked->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $shift = $hoursWorked->shift;

            if ($shift === null || ! $this->access->canCheckOutForShift($actor, $shift)) {
                throw HoursCorrectionException::unauthorized();
            }

            if ($hoursWorked->frozen_at !== null) {
                return $hoursWorked;
            }

            $before = $this->auditSnapshot($hoursWorked, $hoursWorked->attendanceRecord);

            $hoursWorked->forceFill(['frozen_at' => $frozenAt])->save();
            $hoursWorked = $hoursWorked->refresh()->load(['attendanceRecord', 'event', 'shift']);

            $this->audit->recordForEntity(
                entity: $hoursWorked,
                action: 'hours.frozen',
                actorUser: $actor,
                organizationId: $hoursWorked->event?->organization_id,
                eventId: $hoursWorked->event_id,
                departmentId: $hoursWorked->department_id,
                before: $before,
                after: $this->auditSnapshot($hoursWorked, $hoursWorked->attendanceRecord),
                sourceContext: $sourceContext,
            );

            return $hoursWorked;
        });
    }

    /**
     * @throws HoursCorrectionException
     */
    private function acceptedExisting(
        AttendanceOperation $operation,
        HoursWorked $hoursWorked,
        User $actor,
        ?string $assignmentId,
    ): HoursCorrectionResult {
        $sameOperation = $operation->operation_type === AttendanceOperation::TYPE_CORRECT
            && (string) $operation->shift_id === (string) $hoursWorked->shift_id
            && (string) $operation->shift_assignment_id === (string) $assignmentId
            && (string) $operation->staff_id === (string) $hoursWorked->staff_id
            && (string) $operation->created_by_user_id === (string) $actor->id;

        if (! $sameOperation) {
            throw HoursCorrectionException::operationUuidConflict();
        }

        $hoursWorked = $hoursWorked->refresh()->load('attendanceRecord');

        return new HoursCorrectionResult(
            operation: $operation,
            record: $hoursWorked->attendanceRecord,
            hoursWorked: $hoursWorked,
            createdCorrection: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(
        HoursWorked $hoursWorked,
        ?AttendanceRecord $record,
        ?AttendanceOperation $operation = null,
    ): array {
        return [
            'operation_uuid' => $operation?->operation_uuid,
            'operation_type' => $operation?->operation_type,
            'attendance_record_id' => $hoursWorked->attendance_record_id,
            'hours_worked_id' => $hoursWorked->id,
            'event_id' => $hoursWorked->event_id,
            'department_id' => $hoursWorked->department_id,
            'team_id' => $hoursWorked->shift?->eligible_team_id,
            'shift_id' => $hoursWorked->shift_id,
            'shift_assignment_id' => $record?->shift_assignment_id,
            'staff_id' => $hoursWorked->staff_id,
            'current_state' => $record?->current_state,
            'checked_in_at' => $record?->checked_in_at?->toIso8601String(),
            'checked_out_at' => $record?->checked_out_at?->toIso8601String(),
            'corrected_at' => $record?->corrected_at?->toIso8601String(),
            'actual_started_at' => $hoursWorked->actual_started_at?->toIso8601String(),
            'actual_ended_at' => $hoursWorked->actual_ended_at?->toIso8601String(),
            'minutes_worked' => $hoursWorked->minutes_worked,
            'hours_status' => $hoursWorked->status,
            'corrected_by_user_id' => $hoursWorked->corrected_by_user_id,
            'server_corrected_at' => $hoursWorked->server_corrected_at?->toIso8601String(),
            'frozen_at' => $hoursWorked->frozen_at?->toIso8601String(),
        ];
    }
}
