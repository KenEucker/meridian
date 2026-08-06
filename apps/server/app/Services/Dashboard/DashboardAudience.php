<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardWidgetGroup;
use App\Models\Department;
use App\Models\SharedWorkstation;
use App\Services\DepartmentOps\DepartmentOperationsAuthority;

/**
 * Who is reading a dashboard, and which of the six groups that opens (M18.28;
 * UI contract 13.1 through 13.6).
 *
 * Resolved once per read, because the widget compilers all ask the same
 * questions and a compiler that resolved its own authority would be a second
 * place the rules live. Every flag here is the answer the owning surface's own
 * gate gives: department standing comes from `DepartmentOperationsAccess`, IC
 * standing from `IncidentReadAccess`, Field Report reach from
 * `FieldReportVisibilityAccess`. A dashboard shows nothing its reader could not
 * open the surface behind it and see.
 *
 * The organizer/IC separation is the load-bearing one. Holding organizer
 * standing opens the organizer group and nothing in 13.5; holding IC standing
 * opens 13.5 whether or not the same person organizes. Somebody who holds both
 * reads both, as two groups, which is what UI contract 13.4's exclusion means in
 * practice — the incident data was never reached by being an organizer.
 */
final class DashboardAudience
{
    /**
     * @param  list<string>  $staffIds  The staff records this login speaks for.
     */
    public function __construct(
        public readonly array $staffIds,
        public readonly bool $isEventStaff,
        public readonly ?Department $department,
        public readonly ?DepartmentOperationsAuthority $departmentAuthority,
        /**
         * Whether the reader holds `department_logistics` in this department.
         *
         * Carried separately from the authority block because the contract's
         * 13.2 and 13.3 tables grant four widgets to "department logistics"
         * specifically, and `canManageAttendance` stopped being the same
         * question at M18.13: TEAM-015 resolved authorized attendance manager to
         * logistics *plus* department leads and shift leads, so gating a
         * logistics widget on it would put the Logistics Window's widgets on the
         * dashboard of somebody the Logistics Window itself does not admit.
         */
        public readonly bool $isDepartmentLogistics,
        public readonly bool $isOrganizer,
        public readonly bool $hasIncidentCommand,
        public readonly bool $canViewEventFieldReports,
        public readonly ?SharedWorkstation $workstation,
    ) {}

    /**
     * Whether this reader has any dashboard at all.
     *
     * Somebody with a credential and no standing in this event is not refused a
     * widget, they are refused the read: an empty dashboard would say the event
     * has nothing in it rather than that they have nothing here.
     */
    public function hasAnyGroup(): bool
    {
        foreach (DashboardWidgetGroup::cases() as $group) {
            if ($this->opens($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DashboardWidgetGroup>
     */
    public function groups(): array
    {
        return array_values(array_filter(
            DashboardWidgetGroup::cases(),
            fn (DashboardWidgetGroup $group): bool => $this->opens($group),
        ));
    }

    public function opens(DashboardWidgetGroup $group): bool
    {
        return match ($group) {
            DashboardWidgetGroup::Staff => $this->isEventStaff,
            /*
             * 13.2 is granted to department leads and, for two of its widgets,
             * to department logistics. Either opens the group; each widget
             * inside it is still gated on the standing the contract names for
             * it, so a logistics holder who does not lead reads check-in status
             * and equipment returns and not coverage.
             */
            DashboardWidgetGroup::DepartmentLead => $this->departmentAuthority !== null
                && ($this->departmentAuthority->isDepartmentLead || $this->isDepartmentLogistics),
            DashboardWidgetGroup::DepartmentOperations => $this->departmentAuthority !== null
                && $this->departmentAuthority->canViewOperations(),
            DashboardWidgetGroup::Organizer => $this->isOrganizer,
            DashboardWidgetGroup::IncidentCommand => $this->hasIncidentCommand,
            /*
             * The workstation opens the kiosk group and the person signed in at
             * it still gates each widget (widget spec 9): "Kiosk widgets must
             * distinguish trusted workstation state from individual user
             * authority."
             */
            DashboardWidgetGroup::Kiosk => $this->workstation !== null,
        };
    }
}
