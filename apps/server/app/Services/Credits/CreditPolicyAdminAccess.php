<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Organizations\OrganizationConfigurationAccess;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may maintain an organization's credit policies and start calculation
 * runs (M18.16; ORG-009, ORG-020).
 *
 * Organizers and Lead Organizers, through
 * `organization.credit_policies.manage`. Department leads are deliberately
 * absent: ORG-010 rules out a department default policy precisely so a
 * department cannot reprice its own work, and handing a lead the policy list
 * would reopen that door one rename at a time. A lead's part of pricing is
 * choosing which existing policy a shift names, which is shift administration
 * and carries its own authority.
 *
 * Organizer authority is organization-scoped through the configured Organizers
 * Department (technical spec 15.2), so the grant's team has to belong to the
 * organization being edited — the same check
 * {@see OrganizationConfigurationAccess} makes.
 */
final class CreditPolicyAdminAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canManageCreditPolicies(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_CREDIT_POLICIES_MANAGE,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team === null || $team->department === null) {
                    continue;
                }

                if ((string) $team->department->organization_id === (string) $organization->id) {
                    return true;
                }
            }
        }

        return false;
    }
}
