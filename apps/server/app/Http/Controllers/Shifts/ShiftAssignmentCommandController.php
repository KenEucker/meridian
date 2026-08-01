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
 * Connected-only. Unlike check-in it is not in the closed set of Alpha 1 offline
 * writes (data/API 7.2), and the eligibility it turns on is not a question a
 * device can answer for itself.
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
        ]);

        try {
            $outcome = $additions->addStaffToShift(
                shift: Shift::query()->findOrFail((string) $validated['shift_id']),
                staff: Staff::query()->findOrFail((string) $validated['staff_id']),
                actor: $user,
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
