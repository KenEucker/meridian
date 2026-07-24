<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Organization/department/team narrowing for records that carry an owning
 * organization plus an audience scope pair (`scope_type` + `scope_id`), such as
 * policy documents, procedure documents, and document fragments.
 *
 * Department and team narrowing match the audience scope, so an
 * organization-wide document is not returned when narrowing to one department.
 */
trait FiltersByAudienceScope
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInOrganization(Builder $query, string $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInDepartment(Builder $query, string $departmentId): Builder
    {
        return $query
            ->where('scope_type', static::SCOPE_DEPARTMENT)
            ->where('scope_id', $departmentId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInTeam(Builder $query, string $teamId): Builder
    {
        return $query
            ->where('scope_type', static::SCOPE_TEAM)
            ->where('scope_id', $teamId);
    }
}
