<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Event;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Authorization and scope resolution for the credential eligibility export
 * (M13.1; REPORT-001, REPORT-006, REPORT-007).
 *
 * Authority is never inferred from the requested event alone: a role only
 * counts when the team carrying it belongs to a department of the event's own
 * organization, so an organizer of one organization cannot export another
 * organization's event.
 */
final class CredentialEligibilityExportAccess
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
     * Resolve what the user may export for the event, or null when the user
     * may not export it at all.
     */
    public function resolve(User $user, Event $event): ?CredentialEligibilityExportScope
    {
        $organizationWide = false;
        $departmentIds = [];

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff, $event) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
                )) {
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

        return new CredentialEligibilityExportScope(
            organizationWide: $organizationWide,
            departmentIds: array_values(array_unique($departmentIds)),
        );
    }
}
