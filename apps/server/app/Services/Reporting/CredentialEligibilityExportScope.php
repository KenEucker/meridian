<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * The slice of an event's credential eligibility a caller may export.
 *
 * Organizers hold an organization-wide scope over their own organization's
 * event (REPORT-006). Department leads and department administration hold a
 * department-scoped view of the same event (REPORT-007), which is why the two
 * cases are one value object rather than two code paths: the export service
 * asks the scope what to include and never re-derives authority.
 */
final class CredentialEligibilityExportScope
{
    /**
     * @param  list<string>  $departmentIds  Departments the caller may export, empty for an organization-wide scope.
     */
    public function __construct(
        public readonly bool $organizationWide,
        public readonly array $departmentIds = [],
    ) {}

    public function includesDepartment(string $departmentId): bool
    {
        return $this->organizationWide || in_array($departmentId, $this->departmentIds, true);
    }

    /**
     * Narrow the scope to a single department the caller already covers.
     *
     * An organizer may narrow an event-wide scope to one department; a
     * department lead may only "narrow" to a department they already hold.
     */
    public function restrictedToDepartment(string $departmentId): self
    {
        return new self(false, [$departmentId]);
    }
}
