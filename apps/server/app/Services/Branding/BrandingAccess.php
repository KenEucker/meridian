<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may edit which branding profile (M15A.6, M15A.7; BRAND-019).
 *
 * Both gates in one class because the boundary between them is the point.
 * Organization branding is organizer-only; department branding — and the team
 * logos beneath it — is scoped to the department the role is held in. An organizer editing an organization
 * palette is not thereby editing every department's accent, and a department
 * lead holding `department.branding.manage` in Rangers may not touch Gate.
 *
 * Reading branding is not gated at all — see
 * {@see \App\Http\Controllers\Branding\BrandingReadController}. Every signed-in
 * user sees the organization's identity on every screen; that is BRAND-002.
 */
final class BrandingAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageOrganizationBranding(User $user, Organization $organization): bool
    {
        foreach ($this->teamsGranting($user, PermissionCatalog::PERMISSION_ORGANIZATION_BRANDING_MANAGE) as $team) {
            if ($team->department !== null
                && (string) $team->department->organization_id === (string) $organization->getKey()) {
                return true;
            }
        }

        return false;
    }

    public function canManageDepartmentBranding(User $user, Department $department): bool
    {
        foreach ($this->teamsGranting($user, PermissionCatalog::PERMISSION_DEPARTMENT_BRANDING_MANAGE) as $team) {
            if ((string) $team->department_id === (string) $department->getKey()) {
                return true;
            }
        }

        // An organizer may reach a department's branding too. BRAND-019 says
        // departments edit *only* their own profile; it does not fence
        // organizers out of the organization they run, and an organizer who
        // could switch department overrides off but could not fix a single
        // department's unreadable accent would be stuck with the blunt tool.
        $organization = $department->organization;

        return $organization instanceof Organization
            && $this->canManageOrganizationBranding($user, $organization);
    }

    /**
     * A team logo is department identity at a finer grain (BRAND-025), so it is
     * gated on the department's branding authority rather than on a permission
     * of its own.
     *
     * Team leads are deliberately not included. A team lead runs a team's
     * shifts and roster; the team's mark appears on department surfaces beside
     * every other team's, and letting each lead change one independently is how
     * a department's screens stop looking like one department.
     */
    public function canManageTeamBranding(User $user, Team $team): bool
    {
        $department = $team->department;

        return $department instanceof Department
            && $this->canManageDepartmentBranding($user, $department);
    }

    /**
     * An event mark is organizer authority, not department authority
     * (BRAND-028, BRAND-019).
     *
     * An event's mark is what most of its staff will take the whole product to
     * be, and an event spans every department in it — so this belongs to the
     * people who own the organization's identity, not to any one department
     * that happens to be working the event.
     */
    public function canManageEventBranding(User $user, Event $event): bool
    {
        $organization = $event->organization;

        return $organization instanceof Organization
            && $this->canManageOrganizationBranding($user, $organization);
    }

    /**
     * @return list<Team>
     */
    private function teamsGranting(User $user, string $permission): array
    {
        $teams = [];

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission($role->roleCode, $permission)) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team instanceof Team) {
                    $teams[] = $team;
                }
            }
        }

        return $teams;
    }
}
