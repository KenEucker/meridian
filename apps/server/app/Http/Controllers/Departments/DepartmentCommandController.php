<?php

namespace App\Http\Controllers\Departments;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Services\Departments\DepartmentAdminAccess;
use App\Services\Departments\DepartmentAdminException;
use App\Services\Departments\DepartmentAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DepartmentCommandController extends Controller
{
    public function create(
        Request $request,
        DepartmentAdminAccess $access,
        DepartmentAdminService $departments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'description' => ['nullable', 'string'],
        ]);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageDepartments($user, $organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage departments for this organization.',
            ], 403);
        }

        try {
            $department = $departments->create(
                $organization,
                [
                    'name' => (string) $validated['name'],
                    'code' => (string) $validated['code'],
                    'description' => $validated['description'] ?? null,
                ],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (DepartmentAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return response()->json($this->payload($department), 201);
    }

    public function update(
        Request $request,
        DepartmentAdminAccess $access,
        DepartmentAdminService $departments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash'],
            'description' => ['nullable', 'string'],
        ]);

        $department = Department::query()
            ->with('organization')
            ->findOrFail((string) $validated['department_id']);

        if (! $access->canManageDepartments($user, $department->organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage departments for this organization.',
            ], 403);
        }

        try {
            $department = $departments->update(
                $department,
                [
                    'name' => (string) $validated['name'],
                    'code' => (string) $validated['code'],
                    'description' => $validated['description'] ?? null,
                ],
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (DepartmentAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($department));
    }

    public function archive(
        Request $request,
        DepartmentAdminAccess $access,
        DepartmentAdminService $departments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
        ]);

        $department = Department::query()
            ->with('organization')
            ->findOrFail((string) $validated['department_id']);

        if (! $access->canManageDepartments($user, $department->organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage departments for this organization.',
            ], 403);
        }

        try {
            $department = $departments->archive($department, $user, AuditEvent::SOURCE_API);
        } catch (DepartmentAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($department));
    }

    public function restore(
        Request $request,
        DepartmentAdminAccess $access,
        DepartmentAdminService $departments,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
        ]);

        $department = Department::query()
            ->with('organization')
            ->findOrFail((string) $validated['department_id']);

        if (! $access->canManageDepartments($user, $department->organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage departments for this organization.',
            ], 403);
        }

        try {
            $department = $departments->restore($department, $user, AuditEvent::SOURCE_API);
        } catch (DepartmentAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($department));
    }

    /**
     * @return array{
     *     id: string,
     *     organization_id: string,
     *     name: string,
     *     code: string,
     *     description: string|null,
     *     default_team_id: string|null,
     *     archived_at: string|null,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
     */
    private function payload(Department $department): array
    {
        return [
            'id' => (string) $department->id,
            'organization_id' => (string) $department->organization_id,
            'name' => $department->name,
            'code' => $department->code,
            'description' => $department->description,
            'default_team_id' => $department->default_team_id !== null
                ? (string) $department->default_team_id
                : null,
            'archived_at' => $department->archived_at?->toIso8601String(),
            'created_at' => $department->created_at?->toIso8601String(),
            'updated_at' => $department->updated_at?->toIso8601String(),
        ];
    }
}
