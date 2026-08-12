<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * The tables that replicate to a device, and the module that owns each
 * (MOD-016; technical spec 9.5, 11A.7, 15A.5; data/API 7.6; M19.17).
 *
 * Data/API 7.6 asks for a declaration at the grain of the table: "every synced
 * table declares its owning module, or declares itself core". That is a
 * different grain from {@see DomainNamespace}, which declares ownership at the
 * grain of a code folder, and a different grain again from the offline read
 * set's sections, which are projections a client stores by name. This enum is
 * the table one, and it does not restate the other two — a case names the
 * namespace it belongs to and the module follows from there, so a namespace
 * that moves between modules in technical spec 15A.2 moves its tables with it
 * and nothing here has to be edited to agree.
 *
 * Only the tables a device actually receives are listed. Meridian has a hundred
 * or so tables and most of them never leave the node: the audit trail, node
 * operations, credentials, imports, and the whole administrative half of the
 * product are read through the API or not at all. Listing them here would
 * declare a boundary that nothing enforces and invite the reader to believe a
 * device holds them.
 *
 * A table this enum has never heard of is core. {@see self::ownerOf()} answers
 * null for one, which is data/API 7.6's own rule — "a table with no declaration
 * is core, so an undeclared table stays replicating rather than silently
 * disappearing from devices" — and it is the same safe direction
 * {@see DomainNamespace} takes for an undeclared namespace. The coverage test is
 * what keeps that from becoming an excuse: a read-set section carrying a table
 * nobody declared fails `SyncedTableOwnershipTest` rather than shipping.
 */
enum SyncedTable: string
{
    // Core (MOD-004). A device holds these whatever the organization runs.
    case Organizations = 'organizations';

    case Events = 'events';

    case EventDepartmentAssignments = 'event_department_assignments';

    case Departments = 'departments';

    case Teams = 'teams';

    case Staff = 'staff';

    case StaffOrganizationStatuses = 'staff_organization_statuses';

    case DepartmentMemberships = 'department_memberships';

    case TeamMemberships = 'team_memberships';

    case AttendanceRecords = 'attendance_records';

    case EventDepartmentPresences = 'event_department_presences';

    // Scheduling (MOD-002).
    case Shifts = 'shifts';

    case ShiftAssignments = 'shift_assignments';

    // Incident Management (MOD-002).
    case FieldReports = 'field_reports';

    case FieldReportAppends = 'field_report_appends';

    // Documents (MOD-002).
    case PolicyDocuments = 'policy_documents';

    case ProcedureDocuments = 'procedure_documents';

    case DocumentFragments = 'document_fragments';

    case DocumentFragmentReferences = 'document_fragment_references';

    case DocumentAcknowledgments = 'document_acknowledgments';

    case DocumentAcknowledgmentRequirements = 'document_acknowledgment_requirements';

    // Equipment (MOD-002).
    case EquipmentItems = 'equipment_items';

    case EquipmentCheckouts = 'equipment_checkouts';

    // Event Geography (MOD-002).
    case Deployments = 'deployments';

    case CurrentDeploymentAssignments = 'current_deployment_assignments';

    /**
     * The domain namespace this table belongs to.
     *
     * Attendance records and department presence are Attendance, which is core:
     * check-in does not require a shift (requirements 5.8), so a device belonging
     * to an organization with Scheduling inactive still holds who worked and who
     * is on site. A *section* of the read set may still narrow further than its
     * table — the Logistics desk's attendance list is attendance for shifts, and
     * declares Scheduling — because a projection may be about less than the table
     * it reads. It may never be about more, and that is the rule
     * `SyncedTableOwnershipTest` pins.
     */
    public function namespace(): DomainNamespace
    {
        return match ($this) {
            self::Organizations => DomainNamespace::Organizations,
            self::Events,
            self::EventDepartmentAssignments => DomainNamespace::Events,
            self::Departments => DomainNamespace::Departments,
            self::Teams => DomainNamespace::Teams,
            self::Staff,
            self::StaffOrganizationStatuses => DomainNamespace::Staff,
            self::DepartmentMemberships,
            self::TeamMemberships => DomainNamespace::Memberships,
            self::AttendanceRecords,
            self::EventDepartmentPresences => DomainNamespace::Attendance,

            self::Shifts => DomainNamespace::Shifts,
            self::ShiftAssignments => DomainNamespace::ShiftSignupsAndRequirements,

            self::FieldReports,
            self::FieldReportAppends => DomainNamespace::FieldReports,

            self::PolicyDocuments => DomainNamespace::PolicyDocuments,
            self::ProcedureDocuments => DomainNamespace::ProcedureDocuments,
            self::DocumentFragments,
            self::DocumentFragmentReferences => DomainNamespace::DocumentFragments,
            self::DocumentAcknowledgments,
            self::DocumentAcknowledgmentRequirements => DomainNamespace::DocumentAcknowledgments,

            self::EquipmentItems,
            self::EquipmentCheckouts => DomainNamespace::Equipment,

            self::Deployments,
            self::CurrentDeploymentAssignments => DomainNamespace::Deployments,
        };
    }

    /**
     * The module that owns this table, or null when it is core.
     */
    public function module(): ?ModuleKey
    {
        return $this->namespace()->module();
    }

    public function isCore(): bool
    {
        return $this->module() === null;
    }

    /**
     * The module owning a table named by string, or null when it is core *or*
     * undeclared (data/API 7.6).
     *
     * The two answers are the same one on purpose, for the reason
     * {@see DomainNamespace::ownerOf()} gives: a caller asking this wants to know
     * whether to withhold the table from a device, and "no declaration" has to
     * mean "do not withhold".
     */
    public static function ownerOf(string $table): ?ModuleKey
    {
        return self::tryFrom($table)?->module();
    }

    /**
     * The tables this module owns.
     *
     * @return list<self>
     */
    public static function ownedBy(ModuleKey $module): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $table): bool => $table->module() === $module,
        ));
    }

    /**
     * @return list<self>
     */
    public static function core(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $table): bool => $table->isCore(),
        ));
    }
}
