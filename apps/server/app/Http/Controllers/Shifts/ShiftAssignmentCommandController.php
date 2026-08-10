<?php

namespace App\Http\Controllers\Shifts;

use App\Domain\Commands\ShiftAdditionRefusalReason;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\Staff;
use App\Services\Shift\ShiftAssignmentOutcome;
use App\Services\Shift\ShiftOverlapWarning;
use App\Services\Shift\UnscheduledShiftAdditionException;
use App\Services\Shift\UnscheduledShiftAdditionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

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
 *
 * **What M18.55 adds is a second thing a person may do with that refusal**
 * (CLIENT-017A). `overrideUnscheduledStaff` is a distinct command naming the
 * refused one, and the refusal grew a machine-readable `reason_code` to make it
 * possible: the client decides whether to *offer* an override by holding that
 * code against the published allowlist, and the node decides whether to *apply*
 * one by holding it against the same list plus the caller's authority. The two
 * answers are not the same answer arrived at twice — the client's is about what
 * to render and the node's is the decision — and only the node's is trusted.
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
            return $this->refusal($exception);
        }

        return $this->accepted($outcome);
    }

    /**
     * Re-issue a refused addition as an override (M18.55; CLIENT-017A).
     *
     * A separate endpoint rather than a flag on the one above, for the same
     * reason it is a separate command: an override is a different act, made
     * under a different authority, and a request that can only be made
     * deliberately cannot be made by a client that forgot to send a `false`.
     *
     * The payload names three things the ordinary addition does not: which
     * refusal is being overridden, which command earned it, and — through the
     * caller's own credential — who decided to proceed. Everything the node
     * needs to refuse this is checked in the service, including the allowlist,
     * so a client that offers the control where it should not have gets an
     * answer rather than an effect.
     */
    public function overrideUnscheduledStaff(
        Request $request,
        UnscheduledShiftAdditionService $additions,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'shift_id' => ['required', 'uuid', 'exists:shifts,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            /*
             * The refused command's key, required rather than optional: an
             * override that cannot name what it overrode is the collapse into a
             * plain acceptance this command exists to avoid.
             */
            'overridden_operation_uuid' => ['required', 'uuid'],
            /*
             * Validated against every reason the node can refuse for rather
             * than against the overridable ones alone. A client asking to
             * override `do_not_staff` is asking a coherent question and is owed
             * the answer that it is not overridable, which is a rule stated in
             * the service; a 422 about an invalid enum value would tell them
             * their client is broken instead.
             */
            'overridden_reason_code' => [
                'required',
                'string',
                Rule::enum(ShiftAdditionRefusalReason::class),
            ],
            'operation_uuid' => ['nullable', 'uuid'],
            'device_created_at' => ['nullable', 'date'],
        ]);

        try {
            $outcome = $additions->overrideRefusedAddition(
                shift: Shift::query()->findOrFail((string) $validated['shift_id']),
                staff: Staff::query()->findOrFail((string) $validated['staff_id']),
                actor: $user,
                overriddenReason: ShiftAdditionRefusalReason::from(
                    (string) $validated['overridden_reason_code'],
                ),
                overriddenOperationUuid: (string) $validated['overridden_operation_uuid'],
                operationUuid: isset($validated['operation_uuid'])
                    ? (string) $validated['operation_uuid']
                    : null,
                recordedAt: isset($validated['device_created_at'])
                    ? Carbon::parse((string) $validated['device_created_at'])
                    : null,
            );
        } catch (UnscheduledShiftAdditionException $exception) {
            return $this->refusal($exception);
        }

        return $this->accepted($outcome);
    }

    /**
     * The node's refusal, in words and in a code.
     *
     * The words are what a person reads and have not changed. The code is what
     * a client holds against the override allowlist to decide whether to offer
     * the control, and it is null for a refusal that has no code — which the
     * client reads as "not overridable", the same as an unrecognized one.
     */
    private function refusal(UnscheduledShiftAdditionException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'reason_code' => $exception->reasonCode(),
        ], 422);
    }

    private function accepted(ShiftAssignmentOutcome $outcome): JsonResponse
    {
        return response()->json([
            'shift_assignment_id' => (string) $outcome->assignment->id,
            'shift_id' => (string) $outcome->assignment->shift_id,
            'staff_id' => (string) $outcome->assignment->staff_id,
            'assignment_status' => $outcome->assignment->assignment_status,
            'added_at' => $outcome->assignment->created_at?->toIso8601String(),
            'operation_uuid' => $outcome->assignment->unscheduled_operation_uuid,
            /*
             * What the assignment says about its own provenance (M18.55). Null
             * on an ordinary addition; on an override, the refusal that was
             * waived and the command that earned it, so the desk that re-reads
             * the roster sees the same two facts the audit trail holds.
             */
            'overridden_reason_code' => $outcome->assignment->overridden_reason_code,
            'override_of_operation_uuid' => $outcome->assignment->override_of_operation_uuid,
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
