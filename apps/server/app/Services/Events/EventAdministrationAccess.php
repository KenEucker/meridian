<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Organizations\OrganizationConfigurationAccess;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may administer an organization's events (M18.29; UI contract 12.6
 * `organizer.events`; ORG-006).
 *
 * Organizers and Lead Organizers, through `organization.events.manage`, and the
 * grant's team has to belong to the organization whose events are being
 * administered — the same organization-scoping check
 * {@see OrganizationConfigurationAccess} makes, and for the same reason: a role
 * is held somewhere, and an organizer of one organization is nobody in
 * particular in another.
 *
 * A Staff Coordinator is deliberately outside this. TEAM-014 gives that
 * designation application review and "no other organizer governance
 * capability", and declaring when an event runs is governance in the plainest
 * sense — it is what the active event window freeze, the credential window, and
 * the hours grace period are all measured from.
 */
final class EventAdministrationAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageEvents(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_EVENTS_MANAGE,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team === null || $team->department === null) {
                    continue;
                }

                if ((string) $team->department->organization_id === (string) $organization->getKey()) {
                    return true;
                }
            }
        }

        return false;
    }
}
