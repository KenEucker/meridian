<?php

namespace App\Http\Controllers\Shifts;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Shift\ShiftOverlapWarning;
use App\Services\Shift\UnscheduledShiftAdditionException;
use App\Services\Shift\UnscheduledShiftAdditionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Adding an on-site staff member to a shift they were not assigned to
 * (M16.21; SLB-008; SHIFT-016; technical spec 20.5).
 *
 * The Logistics Window's "Add to shift", which until now created an assignment
 * id in the browser and nothing on the node. `UnscheduledShiftAdditionService`
 * owns every condition — the shift has started and is not cancelled, the staff
 * member is on-site, an active department member, an eligible team member, meets
 * the shift's training and waiver requirements, and is not marked do-not-staff —
 * and refuses in its own words.
 *
 * An Alpha 1 offline write since M18.54 (technical spec 9.4, data/API 7.2), so
 * this transport now carries commands out of a device's queue as well as
 * commands typed at a connected desk. Two optional fields are what a queued one
 * adds: the operation UUID it was held under, so a delivery repeated after a
 * lost reply is the same command rather than a duplicate refused as "already
 * assigned"; and the moment the operator recorded it, so the assignment says
 * when it happened rather than when the queue drained.
 *
 * Eligibility is unchanged and is still the node's. A queued addition for
 * somebody the shift may not take comes back refused in
 * `UnscheduledShiftAdditionService`'s own words, and the outbox holds that
 * refusal in front of the person who issued it (CLIENT-017).
 */
final class ShiftAssignmentCommandController extends Controller
{
    public function addUnscheduledStaff(
        Request $request,
        UnscheduledShiftAdditionService $additions,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'shift_id' => ['required', 'uuid', 'exists:shifts,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            /*
             * Optional, because the same endpoint answers a desk that has a node
             * in front of it. A caller that sends neither gets exactly the
             * behavior this command had before it was queueable.
             */
            'operation_uuid' => ['nullable', 'uuid'],
            'device_created_at' => ['nullable', 'date'],
        ]);

        try {
            $outcome = $additions->addStaffToShift(
                shift: Shift::query()->findOrFail((string) $validated['shift_id']),
                staff: Staff::query()->findOrFail((string) $validated['staff_id']),
                actor: $user,
                operationUuid: isset($validated['operation_uuid'])
                    ? (string) $validated['operation_uuid']
                    : null,
                recordedAt: isset($validated['device_created_at'])
                    ? Carbon::parse((string) $validated['device_created_at'])
                    : null,
            );
        } catch (UnscheduledShiftAdditionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'shift_assignment_id' => (string) $outcome->assignment->id,
            'shift_id' => (string) $outcome->assignment->shift_id,
            'staff_id' => (string) $outcome->assignment->staff_id,
            'assignment_status' => $outcome->assignment->assignment_status,
            'added_at' => $outcome->assignment->created_at?->toIso8601String(),
            'operation_uuid' => $outcome->assignment->unscheduled_operation_uuid,
            /*
             * Whether the node applied this now or had already applied it. The
             * device treats both as acceptance — the work is on the roster
             * either way — and the field exists so a replay is legible rather
             * than indistinguishable from a first delivery.
             */
            'replayed' => $outcome->replayed,
            // Overlapping assignments are allowed and warned about rather than
            // refused (technical spec 20.5), so the warnings travel with the
            // acceptance for the desk to show.
            'warnings' => array_map(
                fn (ShiftOverlapWarning $warning): array => [
                    'code' => ShiftOverlapWarning::CODE,
                    'message' => $warning->message(),
                ],
                $outcome->warnings,
            ),
        ], 201);
    }
}
