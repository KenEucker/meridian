<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Authorization and scope resolution for the Alpha 1 reporting exports
 * (REPORT-006, REPORT-007).
 *
 * Authority is never inferred from the requested event alone: a role only
 * counts when the team carrying it belongs to a department of the event's own
 * organization, so an organizer of one organization cannot export another
 * organization's event.
 *
 * Every export resolves its scope here against its own permission code, so the
 * organizer/department split is decided once rather than once per report.
 */
final class ReportingExportAccess
{
    /**
     * Roles whose export authority covers the whole event (REPORT-006).
     *
     * @var list<string>
     */
    private const ORGANIZATION_WIDE_ROLES = [
        PermissionCatalog::ROLE_ORGANIZER,
        PermissionCatalog::ROLE_LEAD_ORGANIZER,
    ];

    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * Resolve what the user may export for the event under the given export
     * permission, or null when the user may not export it at all.
     */
    public function resolve(User $user, Event $event, string $permission): ?ReportingExportScope
    {
        $organizationWide = false;
        $departmentIds = [];

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (! PermissionCatalog::roleHasPermission($role->roleCode, $permission)) {
                    continue;
                }

                $department = Team::query()->with('department')->find($role->teamId)?->department;

                if ($department === null
                    || (string) $department->organization_id !== (string) $event->organization_id) {
                    continue;
                }

                if (in_array($role->roleCode, self::ORGANIZATION_WIDE_ROLES, true)) {
                    $organizationWide = true;

                    continue;
                }

                $departmentIds[] = (string) $department->id;
            }
        }

        if (! $organizationWide && $departmentIds === []) {
            return null;
        }

        return new ReportingExportScope(
            organizationWide: $organizationWide,
            departmentIds: array_values(array_unique($departmentIds)),
        );
    }
}
