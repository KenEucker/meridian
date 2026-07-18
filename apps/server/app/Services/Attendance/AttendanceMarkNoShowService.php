<?php

namespace App\Services\Attendance;

use App\Models\AttendanceOperation;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Device;
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
 * Connected no-show command for scheduled staff (technical spec section 20.2).
 * HTTP command transport, offline queue reconciliation, and UI controls remain
 * with later M10 tasks.
 */
class AttendanceMarkNoShowService
{
    public function __construct(
        private readonly AttendanceCheckInAccess $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws AttendanceMarkNoShowException
     */
    public function markNoShow(
        Shift $shift,
        Staff $staff,
        User $actor,
        string $operationUuid,
        ?Carbon $deviceCreatedAt = null,
        ?Carbon $serverReceivedAt = null,
        ?Device $originDevice = null,
        ?Node $originNode = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): AttendanceMarkNoShowResult {
        if (! Str::isUuid($operationUuid)) {
            throw AttendanceMarkNoShowException::invalidOperationUuid();
        }

        $serverReceivedAt ??= Carbon::now();
        $deviceCreatedAt ??= $serverReceivedAt->copy();

        return DB::transaction(function () use (
            $shift,
            $staff,
            $actor,
            $operationUuid,
            $deviceCreatedAt,
            $serverReceivedAt,
            $originDevice,
            $originNode,
            $sourceContext,
        ): AttendanceMarkNoShowResult {
            $shift = Shift::query()
                ->with(['event', 'department'])
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->canMarkNoShowForShift($actor, $shift)) {
                throw AttendanceMarkNoShowException::unauthorized();
            }

            if ($shift->isCancelled()) {
                throw AttendanceMarkNoShowException::cancelledShift();
            }

            if ($deviceCreatedAt->lt($shift->starts_at)) {
                throw AttendanceMarkNoShowException::shiftNotStarted();
            }

            $assignment = ShiftAssignment::query()
                ->where('shift_id', $shift->id)
                ->where('staff_id', $staff->id)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw AttendanceMarkNoShowException::noActiveAssignment();
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

            if ($record !== null && in_array($record->current_state, [
                AttendanceRecord::STATE_CHECKED_IN,
                AttendanceRecord::STATE_CHECKED_OUT,
                AttendanceRecord::STATE_CORRECTED,
                AttendanceRecord::STATE_EXCUSED,
            ], true)) {
                throw AttendanceMarkNoShowException::conflictingState();
            }

            $createdStateChange = $record === null || $record->current_state !== AttendanceRecord::STATE_NO_SHOW;

            $operation = AttendanceOperation::query()->create([
                'operation_uuid' => $operationUuid,
                'event_id' => $shift->event_id,
                'department_id' => $shift->department_id,
                'team_id' => $shift->eligible_team_id,
                'shift_id' => $shift->id,
                'shift_assignment_id' => $assignment->id,
                'staff_id' => $staff->id,
                'operation_type' => AttendanceOperation::TYPE_MARK_NO_SHOW,
                'device_created_at' => $deviceCreatedAt,
                'server_received_at' => $serverReceivedAt,
                'created_by_user_id' => $actor->id,
                'origin_device_id' => $originDevice?->id,
                'origin_node_id' => $originNode?->id,
                'source_context' => $sourceContext,
                'created_at' => $serverReceivedAt,
            ]);

            if ($record === null) {
                $record = AttendanceRecord::query()->create([
                    'event_id' => $shift->event_id,
                    'department_id' => $shift->department_id,
                    'shift_id' => $shift->id,
                    'shift_assignment_id' => $assignment->id,
                    'staff_id' => $staff->id,
                    'current_state' => AttendanceRecord::STATE_NO_SHOW,
                    'checked_in_at' => null,
                    'checked_out_at' => null,
                    'no_show_at' => $deviceCreatedAt,
                    'corrected_at' => null,
                ]);
            } else {
                $record->forceFill([
                    'shift_assignment_id' => $assignment->id,
                    'current_state' => AttendanceRecord::STATE_NO_SHOW,
                    'checked_in_at' => null,
                    'checked_out_at' => null,
                    'no_show_at' => $deviceCreatedAt,
                    'corrected_at' => null,
                ])->save();
            }

            $this->audit->recordForEntity(
                entity: $operation,
                action: 'attendance.marked_no_show',
                actorUser: $actor,
                actorDevice: $originDevice,
                actorNode: $originNode,
                organizationId: $shift->event?->organization_id,
                eventId: $shift->event_id,
                departmentId: $shift->department_id,
                after: $this->auditSnapshot($operation, $record->refresh(), $createdStateChange),
                sourceContext: $sourceContext,
            );

            return new AttendanceMarkNoShowResult(
                operation: $operation->refresh(),
                record: $record->refresh(),
                createdStateChange: $createdStateChange,
            );
        });
    }

    /**
     * @throws AttendanceMarkNoShowException
     */
    private function acceptedExisting(
        AttendanceOperation $operation,
        Shift $shift,
        Staff $staff,
        ShiftAssignment $assignment,
        User $actor,
    ): AttendanceMarkNoShowResult {
        $sameOperation = $operation->operation_type === AttendanceOperation::TYPE_MARK_NO_SHOW
            && (string) $operation->shift_id === (string) $shift->id
            && (string) $operation->shift_assignment_id === (string) $assignment->id
            && (string) $operation->staff_id === (string) $staff->id
            && (string) $operation->created_by_user_id === (string) $actor->id;

        if (! $sameOperation) {
            throw AttendanceMarkNoShowException::operationUuidConflict();
        }

        $record = AttendanceRecord::query()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($record === null) {
            throw AttendanceMarkNoShowException::operationUuidConflict();
        }

        return new AttendanceMarkNoShowResult(
            operation: $operation,
            record: $record,
            createdStateChange: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(
        AttendanceOperation $operation,
        AttendanceRecord $record,
        bool $createdStateChange,
    ): array {
        return [
            'operation_uuid' => $operation->operation_uuid,
            'operation_type' => $operation->operation_type,
            'attendance_record_id' => $record->id,
            'event_id' => $operation->event_id,
            'department_id' => $operation->department_id,
            'team_id' => $operation->team_id,
            'shift_id' => $operation->shift_id,
            'shift_assignment_id' => $operation->shift_assignment_id,
            'staff_id' => $operation->staff_id,
            'device_created_at' => $operation->device_created_at?->toIso8601String(),
            'server_received_at' => $operation->server_received_at?->toIso8601String(),
            'current_state' => $record->current_state,
            'no_show_at' => $record->no_show_at?->toIso8601String(),
            'created_state_change' => $createdStateChange,
        ];
    }
}
