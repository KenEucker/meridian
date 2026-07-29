<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * The slice of an event a caller may export, and the authority it rests on
 * (REPORT-006, REPORT-007).
 *
 * Organizers hold an organization-wide scope over their own organization's
 * event (REPORT-006). Department leads and department administration hold a
 * department-scoped view of the same event (REPORT-007), which is why the two
 * cases are one value object rather than two code paths: an export service asks
 * the scope what to include and never re-derives authority.
 *
 * The scope keeps organizer authority and department authority apart instead of
 * collapsing them into one flag, because they answer different questions. Which
 * rows an export contains is {@see self::departmentFilter()}; whether the caller
 * leads the departments those rows belong to is
 * {@see self::coversOnlyOwnDepartments()}. The staff contact export (M13.3)
 * needs both, since a department lead may export emergency contacts for their
 * own department (REPORT-009, VOL-012) while an organizer exporting the event
 * may not (REPORT-010, VOL-011).
 *
 * The same scope answers for every Alpha 1 report, so credential eligibility
 * (M13.1), shift rosters (M13.2), and staff contacts (M13.3) cannot drift apart
 * on who may see what.
 */
final class ReportingExportScope
{
    /**
     * @param  bool  $organizationWide  The caller holds organizer authority over the event (REPORT-006).
     * @param  list<string>  $departmentIds  Departments the caller holds through a department role (REPORT-007).
     * @param  ?string  $onlyDepartmentId  The single department the caller narrowed this export to.
     */
    public function __construct(
        public readonly bool $organizationWide,
        public readonly array $departmentIds = [],
        public readonly ?string $onlyDepartmentId = null,
    ) {}

    /**
     * The departments an export must limit itself to, or an empty list for the
     * whole event.
     *
     * @return list<string>
     */
    public function departmentFilter(): array
    {
        if ($this->onlyDepartmentId !== null) {
            return [$this->onlyDepartmentId];
        }

        return $this->organizationWide ? [] : $this->departmentIds;
    }

    /**
     * Whether the export covers the event rather than named departments. This
     * is the `scope` an export records in its audit entry.
     */
    public function isEventWide(): bool
    {
        return $this->departmentFilter() === [];
    }

    public function includesDepartment(string $departmentId): bool
    {
        return $this->organizationWide || in_array($departmentId, $this->departmentIds, true);
    }

    /**
     * Narrow the scope to a single department the caller already covers.
     *
     * An organizer may narrow an event-wide scope to one department; a
     * department lead may only "narrow" to a department they already hold.
     * Narrowing changes which rows are exported and never how the caller came
     * by their authority, so an organizer asking for one department is still an
     * organizer and still gets an organizer's file (REPORT-010).
     */
    public function restrictedToDepartment(string $departmentId): self
    {
        return new self($this->organizationWide, $this->departmentIds, $departmentId);
    }

    /**
     * Whether every row this scope produces belongs to a department the caller
     * holds through a department role.
     *
     * This is the condition REPORT-009 and VOL-012 attach department-only data
     * to. An organizer exporting the event never satisfies it, and neither does
     * an organizer who narrowed to a department they do not lead.
     */
    public function coversOnlyOwnDepartments(): bool
    {
        $filter = $this->departmentFilter();

        return $filter !== [] && array_diff($filter, $this->departmentIds) === [];
    }
}
