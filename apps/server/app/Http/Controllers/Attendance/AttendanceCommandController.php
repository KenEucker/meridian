<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\HoursWorked;
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
use App\Services\Attendance\HoursCorrectionException;
use App\Services\Attendance\HoursCorrectionService;
use App\Services\Node\NodeSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Attendance command transport for offline queued Shift Lead Board operations.
 *
 * Data/API section 5.2 documents explicit attendance commands, while section
 * 7.2 and technical spec 20.1 allow check-in/check-out/no-show writes to be
 * created offline and accepted later through the same Laravel domain services.
 *
 * `correct-hours` (M18.4) is here rather than in a controller of its own
 * because a correction is an attendance operation: SLB-032 has it written
 * through the same append-only path, with the same device and node provenance
 * on it. It is the one command here that data/API 7.2 does not list as an
 * offline write, so the client sends it connected or is refused where it
 * stands — a correction is measured against a grace period the node's clock
 * owns, and one queued on a device may arrive after that period has closed.
 */
class AttendanceCommandController extends Controller
{
    public function __construct(private readonly NodeSetupService $nodes) {}

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
     * Correct the actual start and end on a recorded hours record (SLB-007,
     * SLB-031, SLB-032; HOURS-007, HOURS-008).
     *
     * Addressed by `hours_worked_id` rather than by shift and staff member,
     * because the record is what is being corrected and it is what the
     * Logistics Desk workspace is holding when an operator opens the dialog.
     * Both actual times are required: a correction that names only one end of
     * the window leaves the other to be inferred, and `HoursCorrectionService`
     * recomputes minutes from both.
     *
     * `HOURS-008` is answered in the service's own words, which now name the
     * date the grace period closed on, and reaches the desk as a 422 alongside
     * every other refusal rather than as a status of its own — the desk prints
     * the sentence either way, and a person reading it needs the sentence.
     */
    public function correctHours(
        Request $request,
        HoursCorrectionService $corrections,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'operation_uuid' => ['required', 'uuid'],
            'hours_worked_id' => ['required', 'uuid', 'exists:hours_worked,id'],
            'actual_started_at' => ['required', 'date'],
            'actual_ended_at' => ['required', 'date'],
            'device_created_at' => ['required', 'date'],
            'origin_device_id' => ['required', 'uuid', 'exists:devices,id'],
            'origin_node_id' => ['nullable', 'uuid', 'exists:nodes,id'],
        ]);

        try {
            $result = $corrections->correctHours(
                hoursWorked: HoursWorked::query()->findOrFail((string) $validated['hours_worked_id']),
                actor: $user,
                operationUuid: (string) $validated['operation_uuid'],
                actualStartedAt: Carbon::parse((string) $validated['actual_started_at']),
                actualEndedAt: Carbon::parse((string) $validated['actual_ended_at']),
                deviceCreatedAt: Carbon::parse((string) $validated['device_created_at']),
                originDevice: $this->originDevice($validated),
                originNode: $this->originNode($validated),
                // Typed at a desk in front of the node, not replayed from a
                // queue, so this is an API write rather than a sync one.
                sourceContext: AuditEvent::SOURCE_API,
            );
        } catch (HoursCorrectionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'operation_uuid' => $result->operation->operation_uuid,
            'operation_id' => $result->operation->id,
            'hours_worked_id' => $result->hoursWorked->id,
            'attendance_record_id' => $result->record->id,
            'actual_started_at' => $result->hoursWorked->actual_started_at?->toIso8601String(),
            'actual_ended_at' => $result->hoursWorked->actual_ended_at?->toIso8601String(),
            'minutes_worked' => $result->hoursWorked->minutes_worked,
            'server_received_at' => optional($result->operation->server_received_at)?->toIso8601String(),
            'created_correction' => $result->createdCorrection,
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
            /*
             * Optional since M16.21. The origin node of a command a client posts
             * here is the node that received it, and a browser has no way to
             * learn a node id — nothing publishes one, deliberately. A caller
             * replaying an operation that originated somewhere else still names
             * that node, which is why the field survives rather than being
             * dropped.
             */
            'origin_node_id' => ['nullable', 'uuid', 'exists:nodes,id'],
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
     * The node this operation originated at.
     *
     * Named by the caller when it is replaying one from somewhere else;
     * otherwise this install's own node, because a command posted to this node
     * originated here. An install with no node configured cannot record
     * provenance at all, and says so rather than recording a guess.
     *
     * @param  array<string, mixed>  $validated
     */
    private function originNode(array $validated): Node
    {
        if (isset($validated['origin_node_id'])) {
            return Node::query()->findOrFail((string) $validated['origin_node_id']);
        }

        $node = $this->nodes->activeNode();

        abort_if($node === null, 422, 'This node is not configured, so an attendance operation cannot record where it came from.');

        return $node;
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
