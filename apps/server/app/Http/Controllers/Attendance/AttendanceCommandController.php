<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Node;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Attendance\AttendanceCheckInException;
use App\Services\Attendance\AttendanceCheckInService;
use App\Services\Attendance\AttendanceCheckOutException;
use App\Services\Attendance\AttendanceCheckOutResult;
use App\Services\Attendance\AttendanceCheckOutService;
use App\Services\Attendance\AttendanceMarkNoShowException;
use App\Services\Attendance\AttendanceMarkNoShowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Attendance command transport for offline queued Shift Lead Board operations.
 *
 * Data/API section 5.2 documents explicit attendance commands, while section
 * 7.2 and technical spec 20.1 allow check-in/check-out/no-show writes to be
 * created offline and accepted later through the same Laravel domain services.
 */
class AttendanceCommandController extends Controller
{
    public function checkIn(
        Request $request,
        AttendanceCheckInService $checkIn,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate($this->baseRules());

        try {
            $result = $checkIn->checkIn(
                shift: $this->shift($validated),
                staff: $this->staff($validated),
                actor: $user,
                operationUuid: (string) $validated['operation_uuid'],
                deviceCreatedAt: Carbon::parse((string) $validated['device_created_at']),
                originDevice: $this->originDevice($validated),
                originNode: $this->originNode($validated),
                sourceContext: AuditEvent::SOURCE_SYNC,
            );
        } catch (AttendanceCheckInException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'operation_uuid' => $result->operation->operation_uuid,
            'operation_id' => $result->operation->id,
            'attendance_record_id' => $result->record->id,
            'current_state' => $result->record->current_state,
            'server_received_at' => optional($result->operation->server_received_at)?->toIso8601String(),
            'created_state_change' => $result->createdStateChange,
        ], 201);
    }

    public function checkOut(
        Request $request,
        AttendanceCheckOutService $checkOut,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            ...$this->baseRules(),
            'actual_started_at' => ['nullable', 'date'],
            'actual_ended_at' => ['nullable', 'date'],
        ]);

        try {
            $result = $checkOut->checkOut(
                shift: $this->shift($validated),
                staff: $this->staff($validated),
                actor: $user,
                operationUuid: (string) $validated['operation_uuid'],
                actualStartedAt: isset($validated['actual_started_at'])
                    ? Carbon::parse((string) $validated['actual_started_at'])
                    : null,
                actualEndedAt: isset($validated['actual_ended_at'])
                    ? Carbon::parse((string) $validated['actual_ended_at'])
                    : null,
                deviceCreatedAt: Carbon::parse((string) $validated['device_created_at']),
                originDevice: $this->originDevice($validated),
                originNode: $this->originNode($validated),
                sourceContext: AuditEvent::SOURCE_SYNC,
            );
        } catch (AttendanceCheckOutException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->checkOutPayload($result), 201);
    }

    public function markNoShow(
        Request $request,
        AttendanceMarkNoShowService $markNoShow,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate($this->baseRules());

        try {
            $result = $markNoShow->markNoShow(
                shift: $this->shift($validated),
                staff: $this->staff($validated),
                actor: $user,
                operationUuid: (string) $validated['operation_uuid'],
                deviceCreatedAt: Carbon::parse((string) $validated['device_created_at']),
                originDevice: $this->originDevice($validated),
                originNode: $this->originNode($validated),
                sourceContext: AuditEvent::SOURCE_SYNC,
            );
        } catch (AttendanceMarkNoShowException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'operation_uuid' => $result->operation->operation_uuid,
            'operation_id' => $result->operation->id,
            'attendance_record_id' => $result->record->id,
            'current_state' => $result->record->current_state,
            'server_received_at' => optional($result->operation->server_received_at)?->toIso8601String(),
            'created_state_change' => $result->createdStateChange,
        ], 201);
    }

    /**
     * @return array<string, list<string>>
     */
    private function baseRules(): array
    {
        return [
            'operation_uuid' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid', 'exists:shifts,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'device_created_at' => ['required', 'date'],
            'origin_device_id' => ['required', 'uuid', 'exists:devices,id'],
            'origin_node_id' => ['required', 'uuid', 'exists:nodes,id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shift(array $validated): Shift
    {
        return Shift::query()->findOrFail((string) $validated['shift_id']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function staff(array $validated): Staff
    {
        return Staff::query()->findOrFail((string) $validated['staff_id']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function originDevice(array $validated): Device
    {
        return Device::query()->findOrFail((string) $validated['origin_device_id']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function originNode(array $validated): Node
    {
        return Node::query()->findOrFail((string) $validated['origin_node_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkOutPayload(AttendanceCheckOutResult $result): array
    {
        return [
            'operation_uuid' => $result->operation->operation_uuid,
            'operation_id' => $result->operation->id,
            'attendance_record_id' => $result->record->id,
            'hours_worked_id' => $result->hoursWorked->id,
            'current_state' => $result->record->current_state,
            'server_received_at' => optional($result->operation->server_received_at)?->toIso8601String(),
            'created_state_change' => $result->createdStateChange,
            'created_hours' => $result->createdHours,
        ];
    }
}
