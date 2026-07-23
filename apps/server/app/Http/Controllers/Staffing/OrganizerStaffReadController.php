<?php

namespace App\Http\Controllers\Staffing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Staff;
use App\Services\Staffing\OrganizerStaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrganizerStaffReadController extends Controller
{
    public function index(
        Request $request,
        Organization $organization,
        OrganizerStaffAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageStaff($user, $organization)) {
            return response()->json([
                'message' => 'You do not have permission to manage staff for this organization.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'prospective', 'active', 'inactive', 'emeritus', 'retired', 'do_not_staff'], true)) {
            return response()->json(['message' => 'Status filter is not supported.'], 422);
        }

        $query = Staff::query()
            ->whereHas('organizationStatuses', function ($query) use ($organization, $status): void {
                $query->where('organization_id', $organization->id);

                if ($status !== 'all') {
                    $query->where('status', $status);
                }
            })
            ->with([
                'users',
                'organizationStatuses',
                'departmentMemberships.department',
                'teamMemberships.team.grants.permissionRole',
            ])
            ->orderBy('legal_name');

        $staff = $query->get()
            ->map(fn (Staff $member): array => OrganizerStaffPayload::staff($member, $organization))
            ->values()
            ->all();

        return response()->json([
            'organization_id' => (string) $organization->id,
            'staff' => $staff,
        ]);
    }
}
