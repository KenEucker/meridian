<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Team;
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

        if (! $access->canAdministerDepartment($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to administer this department.',
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

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $teams = $query->get()->map(fn (Team $team): array => $this->payload($team));

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
            'teams' => $teams->values()->all(),
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

        if (! $access->canAdministerDepartment($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to administer this department.',
            ], 403);
        }

        return response()->json($this->payload($team));
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
}
