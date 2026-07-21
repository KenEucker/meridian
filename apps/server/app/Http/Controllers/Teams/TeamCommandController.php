<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Team;
use App\Services\Departments\DepartmentSelfAdminAccess;
use App\Services\Teams\TeamAdminException;
use App\Services\Teams\TeamAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamCommandController extends Controller
{
    public function create(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamAdminService $teams,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'description' => ['nullable', 'string'],
        ]);

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if (! $access->canAdministerDepartment($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to administer this department.',
            ], 403);
        }

        try {
            $team = $teams->create(
                $department,
                [
                    'name' => (string) $validated['name'],
                    'code' => (string) $validated['code'],
                    'description' => $validated['description'] ?? null,
                ],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (TeamAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return response()->json($this->payload($team), 201);
    }

    public function update(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamAdminService $teams,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'description' => ['nullable', 'string'],
        ]);

        $team = Team::query()
            ->with('department')
            ->findOrFail((string) $validated['team_id']);

        if (! $access->canAdministerDepartment($user, $team->department)) {
            return response()->json([
                'message' => 'You do not have permission to administer this department.',
            ], 403);
        }

        try {
            $team = $teams->update(
                $team,
                [
                    'name' => (string) $validated['name'],
                    'code' => (string) $validated['code'],
                    'description' => $validated['description'] ?? null,
                ],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (TeamAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return response()->json($this->payload($team));
    }

    public function archive(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamAdminService $teams,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
        ]);

        $team = Team::query()
            ->with('department')
            ->findOrFail((string) $validated['team_id']);

        if (! $access->canAdministerDepartment($user, $team->department)) {
            return response()->json([
                'message' => 'You do not have permission to administer this department.',
            ], 403);
        }

        try {
            $team = $teams->archive($team, $user, AuditEvent::SOURCE_API);
        } catch (TeamAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($team));
    }

    public function restore(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamAdminService $teams,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
        ]);

        $team = Team::query()
            ->with('department')
            ->findOrFail((string) $validated['team_id']);

        if (! $access->canAdministerDepartment($user, $team->department)) {
            return response()->json([
                'message' => 'You do not have permission to administer this department.',
            ], 403);
        }

        try {
            $team = $teams->restore($team, $user, AuditEvent::SOURCE_API);
        } catch (TeamAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
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
