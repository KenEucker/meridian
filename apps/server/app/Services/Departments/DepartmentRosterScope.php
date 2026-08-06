<?php

declare(strict_types=1);

namespace App\Services\Departments;

/**
 * What one caller may read on `department.roster` (M18.30; UI contract 12.4;
 * VOL-011, VOL-012).
 *
 * Two questions rather than one flag, because they are decided by different
 * things and a roster answers both at once: which people are on the list, and
 * whether the list carries emergency contacts. A team lead reads a narrow list
 * without them; a department lead reads the whole department with them; a
 * planning holder reads the whole department without them.
 */
final class DepartmentRosterScope
{
    /**
     * @param  bool  $wholeDepartment  Every active membership of the department is in scope.
     * @param  list<string>  $teamIds  The teams the caller leads, when the whole department is not in scope.
     * @param  bool  $emergencyContacts  The caller holds VOL-012 emergency contact access here.
     */
    public function __construct(
        public readonly bool $wholeDepartment,
        public readonly array $teamIds = [],
        public readonly bool $emergencyContacts = false,
    ) {}

    /**
     * @return array{
     *     whole_department: bool,
     *     led_team_ids: list<string>,
     *     emergency_contacts: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'whole_department' => $this->wholeDepartment,
            'led_team_ids' => $this->teamIds,
            'emergency_contacts' => $this->emergencyContacts,
        ];
    }
}
