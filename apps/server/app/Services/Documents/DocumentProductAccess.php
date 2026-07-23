<?php

namespace App\Services\Documents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Product-path policy/procedure/fragment authorization.
 */
final class DocumentProductAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canMaintainScope(
        User $user,
        Organization $organization,
        string $scopeType,
        string $scopeId,
    ): bool {
        return match ($scopeType) {
            DocumentFragment::SCOPE_ORGANIZATION => $this->hasOrganizationMaintainerRole($user, $organization),
            DocumentFragment::SCOPE_DEPARTMENT => $this->hasDepartmentMaintainerRole($user, $scopeId),
            DocumentFragment::SCOPE_TEAM => $this->hasTeamMaintainerRole($user, $scopeId),
            default => false,
        };
    }

    public function canMaintainDocument(User $user, PolicyDocument|ProcedureDocument $document): bool
    {
        $document->loadMissing('organization');

        return $document->organization !== null
            && $this->canMaintainScope($user, $document->organization, $document->scope_type, (string) $document->scope_id);
    }

    public function canMaintainFragment(User $user, DocumentFragment $fragment): bool
    {
        $fragment->loadMissing('organization');

        return $fragment->organization !== null
            && $this->canMaintainScope($user, $fragment->organization, $fragment->scope_type, (string) $fragment->scope_id);
    }

    public function canViewPublishedDocument(User $user, PolicyDocument|ProcedureDocument $document): bool
    {
        if (! $document->isPublished()) {
            return false;
        }

        if ($this->canMaintainDocument($user, $document)) {
            return true;
        }

        $organization = $document->organization;
        if ($organization !== null && $this->hasPublishedPolicyReviewRole($user, $organization)) {
            return true;
        }

        return match ($document->scope_type) {
            PolicyDocument::SCOPE_ORGANIZATION => $organization !== null && $this->hasOrganizationStaffProfile($user, $organization),
            PolicyDocument::SCOPE_DEPARTMENT => $this->hasDepartmentMembership($user, (string) $document->scope_id),
            PolicyDocument::SCOPE_TEAM => $this->hasTeamMembership($user, (string) $document->scope_id),
            default => false,
        };
    }

    public function canViewDocument(User $user, PolicyDocument|ProcedureDocument $document): bool
    {
        return $this->canMaintainDocument($user, $document)
            || $this->canViewPublishedDocument($user, $document);
    }

    private function hasOrganizationMaintainerRole(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! in_array($role->roleCode, [
                    PermissionCatalog::ROLE_ORGANIZER,
                    PermissionCatalog::ROLE_LEAD_ORGANIZER,
                ], true)) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);
                if ($team?->department !== null
                    && (string) $team->department->organization_id === (string) $organization->id) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasPublishedPolicyReviewRole(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_POLICIES_VIEW_PUBLISHED,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);
                if ($team?->department !== null
                    && (string) $team->department->organization_id === (string) $organization->id) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasDepartmentMaintainerRole(User $user, string $departmentId): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_DEPARTMENT_ADMINISTER,
                )) {
                    continue;
                }

                $team = Team::query()->find($role->teamId);
                if ($team !== null && (string) $team->department_id === $departmentId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasTeamMaintainerRole(User $user, string $teamId): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if ($role->roleCode === PermissionCatalog::ROLE_SHIFT_LEAD && $role->teamId === $teamId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasOrganizationStaffProfile(User $user, Organization $organization): bool
    {
        return $user->staffProfiles()
            ->whereHas('organizationStatuses', fn ($query) => $query->where('organization_id', $organization->id))
            ->exists();
    }

    private function hasDepartmentMembership(User $user, string $departmentId): bool
    {
        return $user->staffProfiles()
            ->whereHas('departmentMemberships', fn ($query) => $query
                ->active()
                ->where('department_id', $departmentId))
            ->exists();
    }

    private function hasTeamMembership(User $user, string $teamId): bool
    {
        return $user->staffProfiles()
            ->whereHas('teamMemberships', fn ($query) => $query
                ->active()
                ->where('team_id', $teamId))
            ->exists();
    }
}
