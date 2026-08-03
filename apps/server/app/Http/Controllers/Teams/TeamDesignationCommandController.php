<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Team;
use App\Services\Departments\DepartmentSelfAdminAccess;
use App\Services\Teams\TeamDesignationException;
use App\Services\Teams\TeamDesignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Department team designation commands (M18.12; TEAM-016).
 *
 * Maintained from the department administration surface by whoever holds
 * `department.administer` — department leads and department administration —
 * which is the same authority the surface's other panels answer to. The
 * domain rules live on {@see TeamDesignationService}; this controller only
 * decides who may ask.
 */
final class TeamDesignationCommandController extends Controller
{
    public function designate(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamDesignationService $designations,
    ): JsonResponse {
        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'function_code' => ['required', 'string'],
            'team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if (! $access->canAdministerDepartment($user, $department)) {
            return $this->refusal();
        }

        $team = Team::query()->findOrFail((string) $validated['team_id']);

        try {
            $designations->designateDepartmentTeam(
                $department,
                (string) $validated['function_code'],
                $team,
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (TeamDesignationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($department));
    }

    public function remove(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamDesignationService $designations,
    ): JsonResponse {
        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'function_code' => ['required', 'string'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if (! $access->canAdministerDepartment($user, $department)) {
            return $this->refusal();
        }

        try {
            $designations->removeDepartmentTeam(
                $department,
                (string) $validated['function_code'],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (TeamDesignationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($department));
    }

    /**
     * @return array{department_id: string, designations: list<array<string, string|null>>}
     */
    private function payload(Department $department): array
    {
        return [
            'department_id' => (string) $department->id,
            'designations' => TeamDesignationPayload::forDepartment($department),
        ];
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to administer this department.',
        ], 403);
    }
}
