<?php

declare(strict_types=1);

namespace App\Services\Directory;

/**
 * One authorized chart location: this staff member, at this place in the tree
 * (DIR-009 through DIR-014).
 *
 * Visibility constrains locations as well as people (DIR-030), so the unit the
 * rule authorizes is the placement rather than the person: a viewer authorized
 * to see somebody as a team lead of one team holds that placement and not the
 * person's other departments, and a person legitimately appears once per
 * authorized placement (DIR-014).
 */
final readonly class DirectoryPlacement
{
    /**
     * A department lead of the department (DIR-011). Department-level:
     * `$teamId` is null, because the leadership is over the department rather
     * than a location inside one of its teams.
     */
    public const KIND_DEPARTMENT_LEAD = 'department_lead';

    /**
     * A designated lead of the team (DIR-012; `membership_role = 'lead'`,
     * M11.17).
     */
    public const KIND_TEAM_LEAD = 'team_lead';

    /**
     * An ordinary member of the team (DIR-012).
     */
    public const KIND_TEAM_MEMBER = 'team_member';

    /**
     * A member of the department assigned to no team, presented under the
     * department-level Prospectives section (DIR-013). `$teamId` is null.
     */
    public const KIND_PROSPECTIVE = 'prospective';

    public function __construct(
        public string $staffId,
        public string $departmentId,
        public ?string $teamId,
        public string $kind,
    ) {}
}
