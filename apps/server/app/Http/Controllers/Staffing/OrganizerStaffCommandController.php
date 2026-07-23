<?php

namespace App\Http\Controllers\Staffing;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Services\Staffing\OrganizerStaffAccess;
use App\Services\Staffing\OrganizerStaffException;
use App\Services\Staffing\OrganizerStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrganizerStaffCommandController extends Controller
{
    public function addStaff(
        Request $request,
        OrganizerStaffAccess $access,
        OrganizerStaffService $staffing,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'legal_name' => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([
                StaffOrganizationStatus::STATUS_PROSPECTIVE,
                StaffOrganizationStatus::STATUS_ACTIVE,
                StaffOrganizationStatus::STATUS_INACTIVE,
            ])],
            'department_id' => ['nullable', 'uuid'],
            'invite' => ['nullable', 'boolean'],
        ]);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageStaff($user, $organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage staff for this organization.',
            ], 403);
        }

        try {
            $staff = $staffing->addStaff($organization, $validated, $user, AuditEvent::SOURCE_API);
        } catch (OrganizerStaffException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(OrganizerStaffPayload::staff($staff, $organization), 201);
    }

    public function selectDepartmentLead(
        Request $request,
        OrganizerStaffAccess $access,
        OrganizerStaffService $staffing,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'staff_id' => ['required', 'uuid', Rule::exists(Staff::class, 'id')],
        ]);

        $department = Department::query()
            ->with('organization')
            ->findOrFail((string) $validated['department_id']);

        if (! $access->canManageStaff($user, $department->organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage staff for this organization.',
            ], 403);
        }

        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);

        try {
            $membership = $staffing->selectDepartmentLead($staff, $department, $user, AuditEvent::SOURCE_API);
        } catch (OrganizerStaffException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'staff' => OrganizerStaffPayload::staff($staff->refresh(), $department->organization),
            'team_membership' => [
                'id' => (string) $membership->id,
                'team_id' => (string) $membership->team_id,
                'team_name' => $membership->team?->name,
                'membership_role' => $membership->membership_role,
                'archived_at' => $membership->archived_at?->toIso8601String(),
            ],
        ]);
    }

    public function removeDepartmentLead(
        Request $request,
        OrganizerStaffAccess $access,
        OrganizerStaffService $staffing,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'staff_id' => ['required', 'uuid', Rule::exists(Staff::class, 'id')],
        ]);

        $department = Department::query()
            ->with('organization')
            ->findOrFail((string) $validated['department_id']);

        if (! $access->canManageStaff($user, $department->organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage staff for this organization.',
            ], 403);
        }

        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);

        try {
            $membership = $staffing->removeDepartmentLead($staff, $department, $user, AuditEvent::SOURCE_API);
        } catch (OrganizerStaffException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'staff' => OrganizerStaffPayload::staff($staff->refresh(), $department->organization),
            'team_membership' => [
                'id' => (string) $membership->id,
                'team_id' => (string) $membership->team_id,
                'team_name' => $membership->team?->name,
                'membership_role' => $membership->membership_role,
                'archived_at' => $membership->archived_at?->toIso8601String(),
            ],
        ]);
    }
}
