<?php

namespace App\Http\Controllers\Departments;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Organization;
use App\Services\Departments\DepartmentAdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepartmentReadController extends Controller
{
    public function index(
        Request $request,
        Organization $organization,
        DepartmentAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageDepartments($user, $organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage departments for this organization.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'archived'], true)) {
            return response()->json([
                'message' => 'Status filter must be all, active, or archived.',
            ], 422);
        }

        $query = Department::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name');

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $departments = $query->get()->map(fn (Department $department): array => $this->payload($department));

        return response()->json([
            'organization_id' => (string) $organization->id,
            'departments' => $departments->values()->all(),
        ]);
    }

    public function show(
        Request $request,
        Organization $organization,
        Department $department,
        DepartmentAdminAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $department->organization_id !== (string) $organization->id) {
            return response()->json(['message' => 'Department not found for this organization.'], 404);
        }

        if (! $access->canManageDepartments($user, $organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage departments for this organization.',
            ], 403);
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
