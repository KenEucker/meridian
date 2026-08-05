<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * The six widget groups of UI contract 13.1 through 13.6 (M18.28).
 *
 * A group is who a widget is for, and it is the unit authorization is decided
 * at before any individual widget is: somebody with no standing in a department
 * is not shown an empty coverage widget, they are not shown the department lead
 * group at all. Widgets inside a group are then gated individually, because the
 * contract grants them separately — a department lead and a department
 * logistics holder read different rows of 13.2.
 *
 * The names are the contract's own. `ic` rather than `incident_command` because
 * that is the prefix every widget id in 13.5 carries, and a group whose name
 * disagreed with its widget ids would be one more thing to translate.
 */
enum DashboardWidgetGroup: string
{
    case Staff = 'staff';
    case DepartmentLead = 'department_lead';
    case DepartmentOperations = 'department_operations';
    case Organizer = 'organizer';
    case IncidentCommand = 'ic';
    case Kiosk = 'kiosk';

    /** The section of UI contract 13 this group's inventory comes from. */
    public function contractSection(): string
    {
        return match ($this) {
            self::Staff => '13.1',
            self::DepartmentLead => '13.2',
            self::DepartmentOperations => '13.3',
            self::Organizer => '13.4',
            self::IncidentCommand => '13.5',
            self::Kiosk => '13.6',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::DepartmentLead => 'Department Lead',
            self::DepartmentOperations => 'Department Operations',
            self::Organizer => 'Organizer',
            self::IncidentCommand => 'Incident Command',
            self::Kiosk => 'Kiosk',
        };
    }

    /**
     * Whether the group is scoped to one department.
     *
     * The two department groups cannot be compiled without one, which is why a
     * dashboard read with no department resolves neither rather than answering
     * for whichever department happened to be first.
     */
    public function requiresDepartment(): bool
    {
        return $this === self::DepartmentLead || $this === self::DepartmentOperations;
    }
}
