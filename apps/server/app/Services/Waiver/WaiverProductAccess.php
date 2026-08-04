<?php

namespace App\Services\Waiver;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Documents\DocumentProductAccess;
use Illuminate\Support\Collection;

/**
 * Product-path waiver administration authorization (WAIVER-010).
 *
 * Waiver administration authority follows the scope of the waiver, matching
 * the policy/procedure maintenance rule: organization-scoped waivers by
 * organizers, department-scoped by department leads, team-scoped by team
 * leads. That rule already exists as {@see DocumentProductAccess}, and the
 * waiver scope types are the document scope types, so this class is a
 * delegation rather than a second copy that could drift.
 */
final class WaiverProductAccess
{
    public function __construct(private readonly DocumentProductAccess $documents) {}

    public function canMaintainScope(
        User $user,
        Organization $organization,
        string $scopeType,
        string $scopeId,
    ): bool {
        return $this->documents->canMaintainScope($user, $organization, $scopeType, $scopeId);
    }

    public function canMaintainWaiver(User $user, Waiver $waiver): bool
    {
        $waiver->loadMissing('organization');

        return $waiver->organization !== null
            && $this->canMaintainScope($user, $waiver->organization, $waiver->scope_type, (string) $waiver->scope_id);
    }

    /**
     * The scopes this caller may administer waivers in, inside one
     * organization. The same list the maintenance check enforces, so what the
     * create form offers and what the command accepts never diverge.
     *
     * @return list<array{scope_type: string, scope_id: string, label: string}>
     */
    public function maintainableScopes(User $user, Organization $organization): array
    {
        return $this->documents->maintainableScopes($user, $organization);
    }

    /**
     * Whether the waiver asks anything of this staff member — the population a
     * completion may be recorded against (WAIVER-001, WAIVER-010).
     *
     * Organization scope asks anybody holding a status with the organization,
     * prospective included, because waivers gate work that has not started
     * yet; department and team scope ask the active members.
     */
    public function appliesToStaff(Waiver $waiver, Staff $staff): bool
    {
        return match ($waiver->scope_type) {
            Waiver::SCOPE_ORGANIZATION => $staff->organizationStatuses()
                ->where('organization_id', $waiver->scope_id)
                ->exists(),
            Waiver::SCOPE_DEPARTMENT => $staff->departmentMemberships()
                ->active()
                ->where('department_id', $waiver->scope_id)
                ->exists(),
            Waiver::SCOPE_TEAM => $staff->teamMemberships()
                ->active()
                ->where('team_id', $waiver->scope_id)
                ->exists(),
            default => false,
        };
    }

    /**
     * The staff the waiver applies to, for the completion-recording roster.
     *
     * @return Collection<int, Staff>
     */
    public function subjectStaff(Waiver $waiver): Collection
    {
        return match ($waiver->scope_type) {
            Waiver::SCOPE_ORGANIZATION => Staff::query()
                ->whereHas('organizationStatuses', fn ($query) => $query
                    ->where('organization_id', $waiver->scope_id))
                ->get(),
            Waiver::SCOPE_DEPARTMENT => Staff::query()
                ->whereHas('departmentMemberships', fn ($query) => $query
                    ->active()
                    ->where('department_id', $waiver->scope_id))
                ->get(),
            Waiver::SCOPE_TEAM => Staff::query()
                ->whereHas('teamMemberships', fn ($query) => $query
                    ->active()
                    ->where('team_id', $waiver->scope_id))
                ->get(),
            default => Staff::query()->whereRaw('1 = 0')->get(),
        };
    }
}
