<?php

namespace App\Services\Application;

use App\Domain\Permissions\PermissionCatalog;
use App\Models\Department;
use App\Models\EventApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ApplicationReviewAccess
{
    public const REVIEW_PERMISSION = 'platform.applications';

    public function canReviewApplications(User $user): bool
    {
        return $user->hasAccess(self::REVIEW_PERMISSION) === true;
    }

    public function canViewApplication(User $user, EventApplication $application): bool
    {
        if ($this->canReviewApplications($user)) {
            return true;
        }

        $departmentIds = $this->departmentLeadDepartmentIds($user);

        if ($departmentIds->isEmpty()) {
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

        $departmentIds = $this->departmentLeadDepartmentIds($user);

        if ($departmentIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('departmentInterests', fn (Builder $interestQuery) => $interestQuery
            ->whereIn('departments.id', $departmentIds));
    }

    public function hasDepartmentLeadVisibility(User $user): bool
    {
        return $this->departmentLeadDepartmentIds($user)->isNotEmpty();
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
