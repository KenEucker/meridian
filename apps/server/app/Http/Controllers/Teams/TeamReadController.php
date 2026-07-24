<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Departments\DepartmentSelfAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeamReadController extends Controller
{
    public function index(
        Request $request,
        Department $department,
        DepartmentSelfAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $canAdminister = $access->canAdministerDepartment($user, $department);
        $ledTeamIds = $access->ledTeamIds($user, $department);

        if (! $canAdminister && $ledTeamIds === []) {
            return response()->json([
                'message' => 'You do not have permission to view department administration for this department.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'archived'], true)) {
            return response()->json([
                'message' => 'Status filter must be all, active, or archived.',
            ], 422);
        }

        $query = Team::query()
            ->where('department_id', $department->id)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $canAdminister) {
            $query->whereIn('id', $ledTeamIds);
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $teams = $query->get()->map(fn (Team $team): array => $this->payload($team));

        $staffTeamIds = $canAdminister
            ? Team::query()
                ->where('department_id', $department->id)
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all()
            : $ledTeamIds;

        return response()->json([
            'department_id' => (string) $department->id,
            'department' => [
                'id' => (string) $department->id,
                'organization_id' => (string) $department->organization_id,
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
                'default_team_id' => $department->default_team_id !== null
                    ? (string) $department->default_team_id
                    : null,
                'archived_at' => $department->archived_at?->toIso8601String(),
            ],
            'access' => [
                'can_administer' => $canAdminister,
                'can_view_led_teams' => $ledTeamIds !== [],
                'led_team_ids' => $ledTeamIds,
            ],
            'teams' => $teams->values()->all(),
            'team_staff' => $this->teamStaffPayload($staffTeamIds),
            'department_staff' => $this->departmentStaffPayload($department),
        ]);
    }

    public function show(
        Request $request,
        Department $department,
        Team $team,
        DepartmentSelfAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $team->department_id !== (string) $department->id) {
            return response()->json(['message' => 'Team not found for this department.'], 404);
        }

        $canAdminister = $access->canAdministerDepartment($user, $department);
        $ledTeamIds = $access->ledTeamIds($user, $department);

        if (! $canAdminister && ! in_array((string) $team->id, $ledTeamIds, true)) {
            return response()->json([
                'message' => 'You do not have permission to view this team.',
            ], 403);
        }

        return response()->json([
            ...$this->payload($team),
            'access' => [
                'can_administer' => $canAdminister,
                'can_view_led_team' => in_array((string) $team->id, $ledTeamIds, true),
            ],
            'team_staff' => $this->teamStaffPayload([(string) $team->id]),
        ]);
    }

    /**
     * @return array{
     *     id: string,
     *     department_id: string,
     *     name: string,
     *     code: string,
     *     description: string|null,
     *     is_default: bool,
     *     archived_at: string|null,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
     */
    private function payload(Team $team): array
    {
        return [
            'id' => (string) $team->id,
            'department_id' => (string) $team->department_id,
            'name' => $team->name,
            'code' => $team->code,
            'description' => $team->description,
            'is_default' => (bool) $team->is_default,
            'archived_at' => $team->archived_at?->toIso8601String(),
            'created_at' => $team->created_at?->toIso8601String(),
            'updated_at' => $team->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $teamIds
     * @return list<array{
     *     staff_id: string,
     *     display_name: string,
     *     handle: string|null,
     *     team_id: string,
     *     team_name: string,
     *     membership_role: string|null
     * }>
     */
    private function teamStaffPayload(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        return TeamMembership::query()
            ->active()
            ->whereIn('team_id', $teamIds)
            ->with(['staff', 'team'])
            ->get()
            ->map(fn (TeamMembership $membership): array => [
                'staff_id' => (string) $membership->staff_id,
                'display_name' => $membership->staff->preferred_name
                    ?: $membership->staff->legal_name,
                'handle' => $membership->staff->handle,
                'team_id' => (string) $membership->team_id,
                'team_name' => $membership->team->name,
                'membership_role' => $membership->membership_role,
            ])
            ->sortBy([
                ['team_name', 'asc'],
                ['display_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * Active department members available for team assignment (M11.17).
     *
     * @return list<array{
     *     staff_id: string,
     *     display_name: string,
     *     handle: string|null
     * }>
     */
    private function departmentStaffPayload(Department $department): array
    {
        return DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->with('staff')
            ->get()
            ->map(fn (DepartmentMembership $membership): array => [
                'staff_id' => (string) $membership->staff_id,
                'display_name' => $membership->staff->preferred_name
                    ?: $membership->staff->legal_name,
                'handle' => $membership->staff->handle,
            ])
            ->sortBy('display_name')
            ->values()
            ->all();
    }
}
