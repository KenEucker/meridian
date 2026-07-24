<?php

namespace App\Http\Controllers\Shifts;

use App\Http\Controllers\Controller;
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
 * Department shift administration reads (M11.17; UI contract 12.4
 * `department.shifts`). Department administer authority sees every department
 * shift; designated team leads see shifts for the teams they lead.
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

        if (! $access->canViewDepartmentShifts($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to view shift administration for this department.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'cancelled'], true)) {
            return response()->json([
                'message' => 'Status filter must be all, active, or cancelled.',
            ], 422);
        }

        $canAdminister = $access->canAdministerDepartment($user, $department);
        $manageableTeamIds = $access->manageableTeamIds($user, $department);

        $query = Shift::query()
            ->where('department_id', $department->id)
            ->with(['eligibleTeam', 'event'])
            ->withCount('activeAssignments')
            ->orderBy('starts_at');

        if (! $canAdminister) {
            $query->whereIn('eligible_team_id', $manageableTeamIds);
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'cancelled') {
            $query->whereNotNull('cancelled_at');
        }

        $shifts = $query->get()->map(fn (Shift $shift): array => $this->payload($shift));

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
                'manageable_team_ids' => $manageableTeamIds,
            ],
            'teams' => $this->teamOptions($department),
            'events' => $this->eventOptions($department),
            'training_options' => $this->trainingOptions($department),
            'waiver_options' => $this->waiverOptions($department),
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
            ...$this->payload($shift),
            'access' => [
                'can_administer' => $access->canAdministerDepartment($user, $department),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shift $shift): array
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
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
            'has_started' => $shift->starts_at !== null && now()->greaterThanOrEqualTo($shift->starts_at),
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
     * @return list<array<string, mixed>>
     */
    private function teamOptions(Department $department): array
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => (string) $team->id,
                'name' => $team->name,
                'code' => $team->code,
                'is_default' => (bool) $team->is_default,
                'archived_at' => $team->archived_at?->toIso8601String(),
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
