<?php

namespace App\Http\Controllers\Staffing;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\TeamGrant;

final class OrganizerStaffPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function staff(Staff $staff, Organization $organization): array
    {
        $staff->loadMissing([
            'users',
            'organizationStatuses',
            'departmentMemberships.department',
            'teamMemberships.team.grants.permissionRole',
        ]);

        $organizationStatus = $staff->organizationStatuses
            ->firstWhere('organization_id', $organization->id);

        $departmentMemberships = $staff->departmentMemberships
            ->filter(fn ($membership): bool => $membership->department !== null
                && (string) $membership->department->organization_id === (string) $organization->id)
            ->values();

        $departmentIds = $departmentMemberships
            ->map(fn ($membership): string => (string) $membership->department_id)
            ->all();

        $leadDepartmentIds = $staff->teamMemberships
            ->filter(fn ($membership): bool => $membership->archived_at === null
                && $membership->team !== null
                && in_array((string) $membership->team->department_id, $departmentIds, true)
                && self::teamHasDepartmentLeadGrant($membership->team->grants))
            ->map(fn ($membership): string => (string) $membership->team->department_id)
            ->unique()
            ->values()
            ->all();

        return [
            'id' => (string) $staff->id,
            'legal_name' => $staff->legal_name,
            'preferred_name' => $staff->preferred_name,
            'display_name' => $staff->displayName(),
            'handle' => $staff->handle,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'city' => $staff->city,
            'state' => $staff->state,
            'organization_status' => $organizationStatus?->status,
            'invited' => $staff->users->isNotEmpty(),
            'departments' => $departmentMemberships
                ->map(fn ($membership): array => [
                    'department_id' => (string) $membership->department_id,
                    'department_name' => $membership->department?->name,
                    'status' => $membership->status,
                    'archived_at' => $membership->archived_at?->toIso8601String(),
                    'is_lead' => in_array((string) $membership->department_id, $leadDepartmentIds, true),
                ])
                ->sortBy('department_name')
                ->values()
                ->all(),
            'lead_department_ids' => $leadDepartmentIds,
            'archived_at' => $staff->archived_at?->toIso8601String(),
        ];
    }

    private static function teamHasDepartmentLeadGrant($grants): bool
    {
        return $grants
            ->filter(fn (TeamGrant $grant): bool => $grant->revoked_at === null)
            ->contains(fn (TeamGrant $grant): bool => $grant->permissionRole?->code === PermissionCatalog::ROLE_DEPARTMENT_LEAD);
    }
}
