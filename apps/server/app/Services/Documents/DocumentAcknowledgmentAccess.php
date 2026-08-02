<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;

/**
 * Who may administer acknowledgment requirements, and who a requirement is
 * actually asking (M18.6; POL-023 through POL-027, POL-046, POL-047).
 *
 * Two questions that look alike and are not. Reviewing is an organizer
 * authority over an organization: `documents.acknowledgments.review` says a
 * document must be acknowledged and reads who has. Being asked is a fact about
 * a person — a requirement reaches somebody through the staff profile that puts
 * them inside its scope, and reaches nobody else at all.
 *
 * The second question is the one with teeth. `DocumentAcknowledgmentService`
 * will happily record any user's acceptance of any active requirement, which was
 * fine while its only caller was a domain test; a command endpoint has to know
 * whether the requirement was ever addressed to the caller, or acknowledgment
 * review becomes a list anybody can write themselves into.
 */
final class DocumentAcknowledgmentAccess
{
    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    /**
     * Whether this caller may maintain and review acknowledgment requirements
     * in this organization.
     */
    public function canReview(User $user, Organization $organization): bool
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_DOCUMENT_ACKNOWLEDGMENTS_REVIEW,
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

    /**
     * The staff profile a requirement reaches this user through, or null when
     * it does not reach them.
     *
     * Organization scope asks anybody holding a status with the organization,
     * whatever the status is. Prospective is deliberately included: POL-024 puts
     * acknowledgment in staff signup, and somebody who has not been approved yet
     * is exactly who a signup requirement is for. Department scope asks the
     * active members of that department and nobody else.
     *
     * The first matching profile is enough. A person with two staff profiles in
     * one organization is a data problem somewhere else; recording their
     * acknowledgment twice would not fix it.
     */
    public function subjectStaffFor(User $user, DocumentAcknowledgmentRequirement $requirement): ?Staff
    {
        foreach ($user->staffProfiles()->get() as $staff) {
            if ($this->requirementReachesStaff($requirement, $staff)) {
                return $staff;
            }
        }

        return null;
    }

    public function requirementReachesStaff(
        DocumentAcknowledgmentRequirement $requirement,
        Staff $staff,
    ): bool {
        return match ($requirement->scope_type) {
            DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION => $staff
                ->organizationStatuses()
                ->where('organization_id', $requirement->scope_id)
                ->exists(),
            DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT => $staff
                ->departmentMemberships()
                ->active()
                ->where('department_id', $requirement->scope_id)
                ->exists(),
            default => false,
        };
    }
}
