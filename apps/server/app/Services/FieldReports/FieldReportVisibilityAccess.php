<?php

namespace App\Services\FieldReports;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Field Report event-wide visibility for IC roles (FR-005, FR-006; technical
 * spec 17.6).
 *
 * Users with an effective role that grants `field_reports.view_event` for the
 * report's event may view all Field Reports for that event. Department leads,
 * organizers, and shift leads do not receive this permission by default.
 */
class FieldReportVisibilityAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canViewEventFieldReports(User $user, Event $event): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            $effectiveRoles = $this->roles->resolveForStaff($staff, $event);

            foreach ($effectiveRoles as $role) {
                if (PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_FIELD_REPORTS_VIEW_EVENT,
                )) {
                    return true;
                }
            }
        }

        return false;
    }
}
