<?php

namespace App\Http\Controllers\Shifts;

use App\Http\Controllers\Controller;
use App\Models\DepartmentMembership;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\User;
use App\Services\Shift\ShiftOverlapWarning;
use App\Services\Shift\ShiftRemovalException;
use App\Services\Shift\ShiftRemovalService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Shift\ShiftSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff self-signup and self-withdrawal (M18.2; SHIFT-011 through SHIFT-014,
 * SHIFT-018; requirements 3.12, 5.5).
 *
 * `ShiftSignupService` and `ShiftRemovalService` have enforced these rules since
 * M7 and only one thing could reach them: the training command, for the shift a
 * training materializes. An ordinary shift had no signup path at all, so a staff
 * member could be shown a schedule and not join it. These two commands are that
 * path, and every rule stays where it already was.
 *
 * Both are self-service. The staff profile is the caller's own — the services
 * refuse a profile the user does not hold — so there is no authority check here
 * beyond holding a credential, and a lead acting for somebody else uses the
 * assignment and unscheduled-addition commands instead.
 *
 * Connected-only. Data/API 7.2 closes the set of Alpha 1 offline writes and
 * signup is not in it: eligibility turns on trainings, waivers, department
 * status, and a capacity count the device cannot see the whole of, and a signup
 * queued against yesterday's answer is a shift somebody thinks they hold.
 */
final class ShiftSignupCommandController extends Controller
{
    public function signUp(Request $request, ShiftSignupService $signups): JsonResponse
    {
        [$user, $shift, $staff] = $this->resolve($request);

        if ($staff === null) {
            return $this->noStaffProfile();
        }

        try {
            $outcome = $signups->signUp($shift, $staff, $user);
        } catch (ShiftSignupException $exception) {
            return $this->refusal($exception->getMessage(), $exception->reasonCode);
        }

        return response()->json([
            'shift_assignment_id' => (string) $outcome->assignment->id,
            'shift_id' => (string) $outcome->assignment->shift_id,
            'staff_id' => (string) $outcome->assignment->staff_id,
            'assignment_status' => $outcome->assignment->assignment_status,
            'signed_up_at' => $outcome->assignment->created_at?->toIso8601String(),
            /*
             * Overlaps warn rather than block (SHIFT-014), so they travel with
             * the acceptance. A signup that produced a warning still happened,
             * and the surface says so rather than treating the warning as a
             * refusal.
             */
            'warnings' => array_map(
                fn (ShiftOverlapWarning $warning): array => [
                    'code' => ShiftOverlapWarning::CODE,
                    'message' => $warning->message(),
                ],
                $outcome->warnings,
            ),
        ], 201);
    }

    public function withdraw(Request $request, ShiftRemovalService $removals): JsonResponse
    {
        [$user, $shift, $staff] = $this->resolve($request);

        if ($staff === null) {
            return $this->noStaffProfile();
        }

        $assignment = ShiftAssignment::query()
            ->active()
            ->where('shift_id', $shift->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($assignment === null) {
            return $this->refusal('You are not signed up for this shift.', 'not_signed_up');
        }

        try {
            $withdrawn = $removals->withdrawFromShift($assignment, $staff, $user);
        } catch (ShiftRemovalException $exception) {
            return $this->refusal($exception->getMessage(), null);
        }

        return response()->json([
            'shift_assignment_id' => (string) $withdrawn->id,
            'shift_id' => (string) $withdrawn->shift_id,
            'staff_id' => (string) $withdrawn->staff_id,
            'withdrawn_at' => $withdrawn->removed_at?->toIso8601String(),
        ]);
    }

    /**
     * The caller, the shift, and the staff profile the command acts for.
     *
     * @return array{0: User, 1: Shift, 2: Staff|null}
     */
    private function resolve(Request $request): array
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'shift_id' => ['required', 'uuid', 'exists:shifts,id'],
            'staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
        ]);

        $shift = Shift::query()
            ->with(['event', 'department'])
            ->findOrFail((string) $validated['shift_id']);

        $staffId = $validated['staff_id'] ?? null;

        return [
            $user,
            $shift,
            $staffId === null
                ? $this->staffProfileFor($user, $shift)
                : Staff::query()->findOrFail((string) $staffId),
        ];
    }

    /**
     * Which of the caller's staff profiles this shift is about.
     *
     * A login can speak for more than one staff record, and only one of them is
     * a member of the shift's department. That one is chosen; with none, the
     * request is refused here rather than sent into the service to come back as
     * "must belong to the shift department", which would be true of a profile
     * the caller never meant to use.
     *
     * A caller who knows which profile they mean sends `staff_id`, and the
     * services check it belongs to them.
     */
    private function staffProfileFor(User $user, Shift $shift): ?Staff
    {
        $staffIds = $user->staffProfiles()->pluck('staff.id')->all();

        if ($staffIds === []) {
            return null;
        }

        $memberStaffId = DepartmentMembership::query()
            ->active()
            ->where('department_id', $shift->department_id)
            ->whereIn('staff_id', $staffIds)
            ->value('staff_id');

        return Staff::query()->find((string) ($memberStaffId ?? $staffIds[0]));
    }

    private function noStaffProfile(): JsonResponse
    {
        return $this->refusal(
            'This login is not linked to a staff profile, so it cannot sign up for shifts.',
            'no_staff_profile',
        );
    }

    /**
     * A refusal in the words the domain wrote it in, with the reason code the
     * shift board renders state from.
     */
    private function refusal(string $message, ?string $reasonCode): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason_code' => $reasonCode,
        ], 422);
    }
}
