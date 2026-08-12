<?php

namespace App\Http\Controllers\Shifts;

use App\Domain\Modules\ModuleKey;
use App\Http\Controllers\Controller;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Modules\ActiveModuleResolver;
use App\Services\Shift\ShiftOverlapService;
use App\Services\Shift\ShiftOverlapWarning;
use App\Services\Shift\ShiftSignupService;
use App\Services\Shift\ShiftSignupVerdict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The staff shift board (M18.2; SHIFT-018; requirements 3.12, 5.5).
 *
 * One response fills the surface: every shift in the event that belongs to a
 * department this staff member is an active member of, each carrying the node's
 * own answer about it — whether they are on it, whether they may join it, and if
 * not, why not.
 *
 * The "why not" is the point. SHIFT-018 requires an unavailable shift to say why
 * it is unavailable using the eligibility reasons in requirements 3.12, and the
 * only reason worth showing is the one the command would refuse with, so each
 * row's verdict comes from `ShiftSignupService::evaluateSignup` rather than from
 * rules re-derived for display. A shift that says it will take you is a shift
 * the command accepts.
 *
 * Cancelled shifts are on the board rather than filtered out of it. Somebody
 * looking for the shift they expected to work is owed "this was cancelled" over
 * a gap in a list, and cancellation is already one of the reasons signup states.
 *
 * Overlap is computed for the shifts a person could actually take, because
 * SHIFT-014 makes it a warning rather than a block: told before signing up, it
 * is information; told only afterwards, it is a surprise.
 */
final class ShiftBoardReadController extends Controller
{
    public function index(
        Request $request,
        Event $event,
        ShiftSignupService $signups,
        ShiftOverlapService $overlaps,
        ActiveModuleResolver $modules,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $moment = Carbon::now();
        $staffByDepartment = $this->staffByDepartment($user);
        $organizationId = (string) $event->organization_id;
        $presentsTrainings = $modules->isActive($organizationId, ModuleKey::Qualifications);
        $presentsWaivers = $modules->isActive($organizationId, ModuleKey::Documents);

        $shifts = $staffByDepartment->isEmpty()
            ? new Collection
            : Shift::query()
                ->where('event_id', $event->id)
                ->whereIn('department_id', $staffByDepartment->keys()->all())
                ->with(['department', 'eligibleTeam', 'event', 'requiredTrainings', 'requiredWaivers'])
                ->withCount('activeAssignments')
                ->orderBy('starts_at')
                ->orderBy('title')
                ->get();

        $staffProfiles = Staff::query()
            ->whereIn('id', $staffByDepartment->values()->unique()->all())
            ->get()
            ->keyBy(fn (Staff $staff): string => (string) $staff->id);

        /*
         * The assignments this person already holds, read once rather than per
         * row. Being on a shift is a fact about the assignment and not about
         * whether signup would be accepted right now: past the cutoff signup is
         * refused, and somebody who is already on the shift still needs to see
         * that they are on it.
         */
        $assignments = ShiftAssignment::query()
            ->active()
            ->whereIn('staff_id', $staffByDepartment->values()->unique()->all())
            ->whereIn('shift_id', $shifts->pluck('id')->all())
            ->get()
            ->keyBy(fn (ShiftAssignment $assignment): string => (string) $assignment->shift_id);

        return response()->json([
            'event' => [
                'id' => (string) $event->id,
                'name' => $event->name,
                'timezone' => $event->timezone,
            ],
            /*
             * The profiles this board was built for, by department. A login can
             * speak for more than one staff record, and which one a row is about
             * is the node's resolution rather than something the client picks.
             */
            'staff_ids_by_department' => $staffByDepartment
                ->map(fn ($staffId): string => (string) $staffId)
                ->all(),
            'shifts' => $shifts
                ->map(function (Shift $shift) use (
                    $staffByDepartment,
                    $staffProfiles,
                    $assignments,
                    $user,
                    $signups,
                    $overlaps,
                    $moment,
                    $presentsTrainings,
                    $presentsWaivers,
                ): array {
                    $staff = $staffProfiles->get(
                        (string) $staffByDepartment->get((string) $shift->department_id),
                    );

                    return $this->payload(
                        $shift,
                        $staff,
                        $assignments->get((string) $shift->id),
                        $user,
                        $signups,
                        $overlaps,
                        $moment,
                        $presentsTrainings,
                        $presentsWaivers,
                    );
                })
                ->values()
                ->all(),
        ]);
    }

    /**
     * The staff profile that answers for each department the caller belongs to.
     *
     * Keyed by department because that is the grain a shift is evaluated at: a
     * login holding two staff records may be a member of one department through
     * one of them and another department through the other, and a board built
     * from a single profile would show the second department's shifts refusing
     * for a membership the person actually holds.
     *
     * @return Collection<string, string>
     */
    private function staffByDepartment(User $user): Collection
    {
        $staffIds = $user->staffProfiles()->pluck('staff.id')->all();

        if ($staffIds === []) {
            return new Collection;
        }

        return DepartmentMembership::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->get()
            ->mapWithKeys(fn (DepartmentMembership $membership): array => [
                (string) $membership->department_id => (string) $membership->staff_id,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Shift $shift,
        ?Staff $staff,
        ?ShiftAssignment $assignment,
        User $user,
        ShiftSignupService $signups,
        ShiftOverlapService $overlaps,
        Carbon $moment,
        bool $presentsTrainings,
        bool $presentsWaivers,
    ): array {
        $verdict = $staff === null
            ? null
            : $signups->evaluateSignup($shift, $staff, $user, $moment);
        $isSignedUp = $assignment !== null;
        $isScheduleLocked = $shift->isScheduleLockedAt($moment);

        return [
            'id' => (string) $shift->id,
            'department_id' => (string) $shift->department_id,
            'department_name' => $shift->department?->name ?? $shift->department_name_snapshot,
            'eligible_team_id' => (string) $shift->eligible_team_id,
            'eligible_team_name' => $shift->eligibleTeam?->name ?? $shift->team_name_snapshot,
            'title' => $shift->title,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'capacity' => $shift->capacity,
            'active_assignment_count' => (int) ($shift->active_assignments_count ?? 0),
            'signup_opens_at' => $shift->signup_opens_at?->toIso8601String(),
            'signup_closes_at' => $shift->signup_closes_at?->toIso8601String(),
            'schedule_lock_at' => $shift->schedule_lock_at?->toIso8601String(),
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
            /*
             * A requirement owned by a module the organization does not run is
             * not presented (MOD-018): the gate behind it evaluates as satisfied
             * in `ShiftEligibilityService`, and naming a training on the board
             * that nothing asks for and nobody can complete would describe a
             * condition that is not there. The rows are read past, not deleted.
             */
            'required_training_names' => $presentsTrainings
                ? $shift->requiredTrainings
                    ->map(fn (Training $training): string => $training->name)
                    ->values()
                    ->all()
                : [],
            'required_waiver_names' => $presentsWaivers
                ? $shift->requiredWaivers
                    ->map(fn (Waiver $waiver): string => $waiver->name)
                    ->values()
                    ->all()
                : [],
            'signed_up' => $isSignedUp,
            'assignment_status' => $assignment?->assignment_status,
            'can_sign_up' => $verdict?->eligible ?? false,
            /*
             * Withdrawal is refused once the schedule locks (SHIFT-013 and
             * section 3.11: staff remove themselves *before* cutoff), and the
             * removal command enforces exactly that. Answering it here keeps the
             * button and the command reading the same clock.
             */
            'can_withdraw' => $isSignedUp && ! $isScheduleLocked,
            'schedule_locked' => $isScheduleLocked,
            // Null on a shift somebody may take, and the reason on every other.
            'unavailable_reason_code' => $verdict === null
                ? 'no_staff_profile'
                : ($verdict->eligible || $isSignedUp ? null : $verdict->reasonCode),
            'unavailable_reason' => $verdict === null
                ? 'This login is not linked to a staff profile for this department.'
                : ($verdict->eligible || $isSignedUp ? null : $verdict->message),
            'overlap_warnings' => $this->overlapWarnings($shift, $staff, $verdict, $overlaps),
        ];
    }

    /**
     * The advisories a signup would come back with (SHIFT-014).
     *
     * Only for a shift somebody could actually take. On a shift already held the
     * overlap is with itself, and on one that refuses there is nothing to warn
     * about — the refusal is the answer.
     *
     * @return list<array<string, string>>
     */
    private function overlapWarnings(
        Shift $shift,
        ?Staff $staff,
        ?ShiftSignupVerdict $verdict,
        ShiftOverlapService $overlaps,
    ): array {
        if ($staff === null || $verdict === null || ! $verdict->eligible) {
            return [];
        }

        return array_map(
            fn (ShiftOverlapWarning $warning): array => [
                'code' => ShiftOverlapWarning::CODE,
                'message' => $warning->message(),
            ],
            $overlaps->warningsFor($staff, $shift),
        );
    }
}
