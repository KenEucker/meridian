<?php

namespace App\Http\Controllers\Shifts;

use App\Http\Controllers\Controller;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\Team;
use App\Models\Training;
use App\Models\Waiver;
use App\Services\Shift\ShiftAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department shift reads (M11.17; bound to the client in M16.18; UI contract
 * 12.4 `department.shifts`).
 *
 * One response fills the whole surface: the department, the caller's authority
 * over it, the option lists its forms need, and the shifts themselves with the
 * node's own answer on each one — whether it has started, and whether this
 * caller may manage it.
 *
 * Three callers reach it and each gets a different answer. Department administer
 * authority sees and manages every department shift. A designated team lead sees
 * and manages shifts for the teams they lead. A department member with team
 * membership reads the shifts their teams are eligible for (SHIFT-004) and
 * manages none of them, which is what the Staff menu's Shifts entry opens; their
 * response carries no option lists, because they have no form to fill.
 */
final class ShiftAdminReadController extends Controller
{
    public function index(
        Request $request,
        Department $department,
        ShiftAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $canAdminister = $access->canAdministerDepartment($user, $department);
        $manageableTeamIds = $access->manageableTeamIds($user, $department);
        $canManage = $canAdminister || $manageableTeamIds !== [];
        $memberTeamIds = $canManage ? [] : $access->memberTeamIds($user, $department);

        if (! $canManage && $memberTeamIds === []) {
            return response()->json([
                'message' => 'You do not have permission to view shifts for this department.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Status filter must be all, active, or cancelled.',
            ], 422);
        }

        $query = Shift::query()
            ->where('department_id', $department->id)
            ->with(['eligibleTeam', 'event'])
            ->withCount('activeAssignments')
            ->orderBy('starts_at');

        if ($canManage) {
            if (! $canAdminister) {
                $query->whereIn('eligible_team_id', $manageableTeamIds);
            }

            if ($status === 'active') {
                $query->active();
            } elseif ($status === 'cancelled') {
                $query->whereNotNull('cancelled_at');
            }
        } else {
            /*
             * A member's list is their own schedule, so the status filter does
             * not apply to it: a cancelled shift is not work anybody is expected
             * at, and it is nothing they could restore.
             */
            $query->whereIn('eligible_team_id', $memberTeamIds)->active();
        }

        $shifts = $query->get()->map(fn (Shift $shift): array => $this->payload(
            $shift,
            $canAdminister || in_array((string) $shift->eligible_team_id, $manageableTeamIds, true),
        ));

        return response()->json([
            'department_id' => (string) $department->id,
            'department' => [
                'id' => (string) $department->id,
                'organization_id' => (string) $department->organization_id,
                'name' => $department->name,
                'code' => $department->code,
                'archived_at' => $department->archived_at?->toIso8601String(),
            ],
            'access' => [
                'can_administer' => $canAdminister,
                'can_manage' => $canManage,
                'manageable_team_ids' => $manageableTeamIds,
            ],
            /*
             * Option lists for the create/edit form, on the terms the caller
             * holds them. `teams` is what the eligible-team field may offer —
             * active teams this caller may schedule for — rather than every team
             * in the department, so which teams a lead may use stays the node's
             * answer instead of a rule the client keeps a second copy of.
             */
            'teams' => $canManage
                ? $this->teamOptions($department, $canAdminister, $manageableTeamIds)
                : [],
            'events' => $canManage ? $this->eventOptions($department) : [],
            'training_options' => $canManage ? $this->trainingOptions($department) : [],
            'waiver_options' => $canManage ? $this->waiverOptions($department) : [],
            'credit_policy_options' => $canManage ? $this->creditPolicyOptions($department) : [],
            'shifts' => $shifts->values()->all(),
        ]);
    }

    public function show(
        Request $request,
        Department $department,
        Shift $shift,
        ShiftAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $shift->department_id !== (string) $department->id) {
            return response()->json(['message' => 'Shift not found for this department.'], 404);
        }

        if (! $access->canManageShift($user, $shift)) {
            return response()->json([
                'message' => 'You do not have permission to manage this shift.',
            ], 403);
        }

        $shift->loadMissing(['eligibleTeam', 'event'])->loadCount('activeAssignments');

        return response()->json([
            // Reaching this line is the manage answer: the guard above is the
            // same one every shift command enforces.
            ...$this->payload($shift, true),
            'access' => [
                'can_administer' => $access->canAdministerDepartment($user, $department),
                'can_manage' => true,
            ],
            'credit_policy_options' => $this->creditPolicyOptions($department, $shift),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shift $shift, bool $canManage): array
    {
        return [
            'id' => (string) $shift->id,
            'event_id' => (string) $shift->event_id,
            'event_name' => $shift->event?->name,
            'department_id' => (string) $shift->department_id,
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
            'schedule_lock_offset_minutes' => $shift->schedule_lock_offset_minutes,
            /*
             * The relative cutoff as the node resolves it right now
             * (SHIFT-017): the event window start minus the offset, or null
             * while the window is not set. The form shows this rather than
             * computing its own, so a moved event window is reflected without
             * the client holding a second copy of the rule.
             */
            'schedule_lock_resolves_to' => $shift->resolvedScheduleLockAt()?->toIso8601String(),
            'credit_policy_id' => $shift->credit_policy_id !== null
                ? (string) $shift->credit_policy_id
                : null,
            'custom_credit_multiplier' => $shift->customCreditMultiplier(),
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
            'has_started' => $shift->starts_at !== null && now()->greaterThanOrEqualTo($shift->starts_at),
            /*
             * Whether this caller may edit, cancel, or restore this shift. The
             * same answer the commands enforce, decided once here rather than
             * re-derived on screen from the team list (CLIENT-006).
             */
            'can_manage' => $canManage,
            'required_training_ids' => $shift->trainingRequirements()
                ->pluck('training_id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all(),
            'required_waiver_ids' => $shift->waiverRequirements()
                ->pluck('waiver_id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all(),
            'created_at' => $shift->created_at?->toIso8601String(),
            'updated_at' => $shift->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The teams a shift may be assigned to by this caller.
     *
     * Archived teams are left out: they cannot be chosen for a new or moved
     * shift (`ShiftAdminService::eligibleTeam`), and a shift that already sits
     * on one keeps saying so through its own `eligible_team_name`.
     *
     * @param  list<string>  $manageableTeamIds
     * @return list<array<string, mixed>>
     */
    private function teamOptions(
        Department $department,
        bool $canAdminister,
        array $manageableTeamIds,
    ): array {
        $query = Team::query()
            ->where('department_id', $department->id)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $canAdminister) {
            $query->whereIn('id', $manageableTeamIds);
        }

        return $query->get()
            ->map(fn (Team $team): array => [
                'id' => (string) $team->id,
                'name' => $team->name,
                'code' => $team->code,
                'is_default' => (bool) $team->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventOptions(Department $department): array
    {
        return Event::query()
            ->where('organization_id', $department->organization_id)
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => (string) $event->id,
                'name' => $event->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trainingOptions(Department $department): array
    {
        return Training::query()
            ->where('organization_id', $department->organization_id)
            ->where(fn ($query) => $query
                ->whereNull('department_id')
                ->orWhere('department_id', $department->id))
            ->orderBy('name')
            ->get()
            ->map(fn (Training $training): array => [
                'id' => (string) $training->id,
                'name' => $training->name,
            ])
            ->values()
            ->all();
    }

    /**
     * The credit policies a shift may name as its own rate (SHIFT-010;
     * M18.16): the organization's active *named* policies, plus the one this
     * shift already names when that policy has since been archived — the
     * select has to be able to render the current choice, and keeping an
     * archived policy is allowed where newly choosing one is not.
     *
     * Shift-scoped rows are left out: each is one shift's custom rate, which
     * the form carries as `custom_credit_multiplier` rather than as an
     * option another shift could pick.
     *
     * @return list<array<string, mixed>>
     */
    private function creditPolicyOptions(Department $department, ?Shift $shift = null): array
    {
        $query = CreditPolicy::query()
            ->where('organization_id', $department->organization_id)
            ->whereNull('shift_id')
            ->where(fn ($constraint) => $constraint
                ->whereNull('archived_at')
                ->when(
                    $shift?->credit_policy_id !== null,
                    fn ($archived) => $archived->orWhere('id', (string) $shift?->credit_policy_id),
                ))
            ->orderBy('name');

        return $query->get()
            ->map(fn (CreditPolicy $policy): array => [
                'id' => (string) $policy->id,
                'name' => $policy->name,
                'credit_multiplier' => (string) $policy->credit_multiplier,
                'archived' => $policy->isArchived(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function waiverOptions(Department $department): array
    {
        return Waiver::query()
            ->where('organization_id', $department->organization_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Waiver $waiver): array => [
                'id' => (string) $waiver->id,
                'name' => $waiver->name,
            ])
            ->values()
            ->all();
    }
}
