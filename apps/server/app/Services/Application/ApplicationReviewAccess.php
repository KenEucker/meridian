<?php

namespace App\Services\Application;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\EventApplication;
use App\Models\Team;
use App\Models\User;
use App\Services\Permissions\EffectiveRoleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may review event applications (APP-005; requirements 4.3, 4.4).
 *
 * Review authority comes from two places. The God Mode console keeps its own
 * `platform.applications` permission, because Orchid support access is not
 * staff standing. The product path answers to the catalog: M18.11 registers
 * `organization.applications.review` on `organizer`, `lead_organizer`, and
 * `staff_coordinator`, all organization-scoped through the configured
 * Organizers Department, so a Staff Coordinator reviews the applications of
 * the organization that designated their team and nobody else's (TEAM-014).
 *
 * Department leads hold no review authority; they see submitted applications
 * naming their department as an interest, which is what lets them plan intake
 * without deciding it.
 */
class ApplicationReviewAccess
{
    public const REVIEW_PERMISSION = 'platform.applications';

    public function __construct(private readonly EffectiveRoleResolver $roles) {}

    public function canReviewApplications(User $user): bool
    {
        return $user->hasAccess(self::REVIEW_PERMISSION) === true;
    }

    /**
     * Whether the user holds organization-scoped application review authority
     * for the given organization (M18.11; TEAM-014, requirements 4.4).
     */
    public function canReviewApplicationsForOrganization(User $user, string $organizationId): bool
    {
        return $this->reviewableOrganizationIds($user)->contains($organizationId);
    }

    /**
     * Whether the user may decide this application: approve, reject, or defer.
     */
    public function canReviewApplication(User $user, EventApplication $application): bool
    {
        if ($this->canReviewApplications($user)) {
            return true;
        }

        return $this->canReviewApplicationsForOrganization($user, (string) $application->organization_id);
    }

    public function canViewApplication(User $user, EventApplication $application): bool
    {
        if ($this->canReviewApplication($user, $application)) {
            return true;
        }

        $departmentIds = $this->departmentLeadDepartmentIds($user);

        if ($departmentIds->isEmpty()) {
            return false;
        }

        if (! $application->isSubmitted()) {
            return false;
        }

        return $application->departmentInterests()
            ->whereIn('departments.id', $departmentIds)
            ->exists();
    }

    /**
     * @param  Builder<EventApplication>  $query
     * @return Builder<EventApplication>
     */
    public function scopeVisibleApplications(Builder $query, User $user): Builder
    {
        if ($this->canReviewApplications($user)) {
            return $query;
        }

        $organizationIds = $this->reviewableOrganizationIds($user);
        $departmentIds = $this->departmentLeadDepartmentIds($user);

        if ($organizationIds->isEmpty() && $departmentIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $visibleQuery) use ($organizationIds, $departmentIds): void {
            if ($organizationIds->isNotEmpty()) {
                $visibleQuery->orWhereIn('organization_id', $organizationIds);
            }

            if ($departmentIds->isNotEmpty()) {
                $visibleQuery->orWhere(fn (Builder $leadQuery) => $leadQuery
                    ->where('status', EventApplication::STATUS_SUBMITTED)
                    ->whereHas('departmentInterests', fn (Builder $interestQuery) => $interestQuery
                        ->whereIn('departments.id', $departmentIds)));
            }
        });
    }

    public function hasDepartmentLeadVisibility(User $user): bool
    {
        return $this->departmentLeadDepartmentIds($user)->isNotEmpty();
    }

    /**
     * The organizations whose applications the user reviews through the
     * catalog: every organization a team carrying
     * `organization.applications.review` belongs to.
     *
     * @return Collection<int, string>
     */
    public function reviewableOrganizationIds(User $user): Collection
    {
        $organizationIds = collect();

        foreach ($user->staffProfiles()->get() as $staff) {
            foreach ($this->roles->resolveForStaff($staff) as $role) {
                if (! PermissionCatalog::roleHasPermission(
                    $role->roleCode,
                    PermissionCatalog::PERMISSION_ORGANIZATION_APPLICATIONS_REVIEW,
                )) {
                    continue;
                }

                $team = Team::query()->with('department')->find($role->teamId);

                if ($team === null || $team->department === null) {
                    continue;
                }

                $organizationIds->push((string) $team->department->organization_id);
            }
        }

        return $organizationIds->unique()->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function departmentLeadDepartmentIds(User $user): Collection
    {
        $staffIds = $user->staffProfiles()
            ->get(['staff.id'])
            ->pluck('id');

        if ($staffIds->isEmpty()) {
            return collect();
        }

        return Department::query()
            ->whereHas('teams', fn (Builder $teamQuery) => $teamQuery
                ->whereHas('memberships', fn (Builder $membershipQuery) => $membershipQuery
                    ->active()
                    ->whereIn('staff_id', $staffIds))
                ->whereHas('grants', fn (Builder $grantQuery) => $grantQuery
                    ->active()
                    ->whereNull('event_id')
                    ->whereHas('permissionRole', fn (Builder $roleQuery) => $roleQuery
                        ->where('code', PermissionCatalog::ROLE_DEPARTMENT_LEAD))))
            ->pluck('departments.id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();
    }
}
