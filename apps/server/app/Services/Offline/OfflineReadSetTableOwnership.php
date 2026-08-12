<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\SyncedTable;

/**
 * Which synced table each section of the offline read set projects (MOD-016;
 * data/API 7.6; M19.17).
 *
 * Data/API 7.6 declares module ownership at the grain of the table. The read set
 * travels at the grain of the *section* — a named list a client stores, often a
 * projection of one table narrowed to a role's scope, sometimes the same table
 * projected twice under two names for two desks. This is the join between the
 * two, in one place rather than spread across the seven contributors, so the
 * question "what does this device hold, and who owns it" has one answer a
 * reviewer can read end to end.
 *
 * The section keeps stating its own module and this does not replace that. The
 * two are related by one rule, and `SyncedTableOwnershipTest` is where it is
 * pinned:
 *
 * **A section may be owned more narrowly than its table, never more widely.**
 *
 * Narrower is legitimate and there are three of them. The Logistics desk's
 * attendance list and the department lead's are attendance *for shifts*, so they
 * belong to Scheduling even though `attendance_records` is core — an
 * organization with Scheduling inactive still records who worked (requirements
 * 5.8, MOD-004), it simply has no shift attendance to send. The Planning Table's
 * team filter is the same shape: `teams` is core, and a list of the teams a
 * shift plan is filtered by is not.
 *
 * Wider is a records leak and is what the test exists to catch. A section
 * carrying `policy_documents` while declaring itself core would put an inactive
 * module's records on a device, which is precisely what MOD-016 forbids, and it
 * is the kind of mistake that is invisible in review because the section reads
 * perfectly well on its own.
 *
 * A section absent from this map is undeclared, and an undeclared table is core
 * (data/API 7.6) — so the failure mode is a section that keeps replicating
 * rather than one that silently disappears. The coverage test is what stops that
 * being a way to skip the decision.
 */
final class OfflineReadSetTableOwnership
{
    /**
     * @var array<string, SyncedTable>
     */
    private const SECTIONS = [
        /*
         * The regular-staff list every staff member receives (technical spec
         * 9.3).
         */
        'staff' => SyncedTable::Staff,
        'staff_organization_statuses' => SyncedTable::StaffOrganizationStatuses,
        'organizations' => SyncedTable::Organizations,
        'departments' => SyncedTable::Departments,
        'teams' => SyncedTable::Teams,
        'department_memberships' => SyncedTable::DepartmentMemberships,
        'team_memberships' => SyncedTable::TeamMemberships,
        'events' => SyncedTable::Events,
        'event_department_assignments' => SyncedTable::EventDepartmentAssignments,
        'shifts' => SyncedTable::Shifts,
        'shift_assignments' => SyncedTable::ShiftAssignments,
        'policy_documents' => SyncedTable::PolicyDocuments,
        'procedure_documents' => SyncedTable::ProcedureDocuments,
        'document_fragments' => SyncedTable::DocumentFragments,
        'document_fragment_references' => SyncedTable::DocumentFragmentReferences,
        'document_acknowledgment_requirements' => SyncedTable::DocumentAcknowledgmentRequirements,
        'document_acknowledgments' => SyncedTable::DocumentAcknowledgments,
        /*
         * The Field Report form is a description of the command rather than a
         * list of rows, and it belongs to the table it describes: an
         * organization that does not run Incident Management is not offered a
         * form for a report it cannot file.
         */
        'field_report_form' => SyncedTable::FieldReports,
        'field_reports' => SyncedTable::FieldReports,
        'field_report_appends' => SyncedTable::FieldReportAppends,

        // The Logistics desk (technical spec 9.3; SLB-020).
        'logistics_staff_index' => SyncedTable::Staff,
        'logistics_presence' => SyncedTable::EventDepartmentPresences,
        'logistics_shift_index' => SyncedTable::Shifts,
        'logistics_shift_assignments' => SyncedTable::ShiftAssignments,
        'logistics_attendance' => SyncedTable::AttendanceRecords,
        'logistics_future_signups' => SyncedTable::ShiftAssignments,
        'logistics_equipment_index' => SyncedTable::EquipmentItems,
        'logistics_equipment_checkouts' => SyncedTable::EquipmentCheckouts,

        // The Operations Center.
        'operations_shift_assignments' => SyncedTable::ShiftAssignments,
        'operations_deployment_options' => SyncedTable::Deployments,
        'operations_current_deployments' => SyncedTable::CurrentDeploymentAssignments,

        /*
         * The Planning Table. Its plan-versus-actual rows are counts the node
         * computed (SLB-019) rather than stored rows, and they are counts *of*
         * shifts, which is the table they are declared against.
         */
        'planning_aggregates' => SyncedTable::Shifts,
        'planning_teams' => SyncedTable::Teams,

        // The Directory projection (DIR-037).
        'directory_departments' => SyncedTable::Departments,
        'directory_people' => SyncedTable::Staff,

        // The shift lead's additions.
        'shift_lead_team_memberships' => SyncedTable::TeamMemberships,
        'shift_lead_staff' => SyncedTable::Staff,
        'shift_lead_shifts' => SyncedTable::Shifts,
        'shift_lead_shift_assignments' => SyncedTable::ShiftAssignments,
        'shift_lead_policy_documents' => SyncedTable::PolicyDocuments,
        'shift_lead_procedure_documents' => SyncedTable::ProcedureDocuments,
        'shift_lead_document_fragments' => SyncedTable::DocumentFragments,
        'shift_lead_document_fragment_references' => SyncedTable::DocumentFragmentReferences,

        // The department lead's additions.
        'department_lead_departments' => SyncedTable::Departments,
        'department_lead_teams' => SyncedTable::Teams,
        'department_lead_department_memberships' => SyncedTable::DepartmentMemberships,
        'department_lead_team_memberships' => SyncedTable::TeamMemberships,
        'department_lead_staff' => SyncedTable::Staff,
        'department_lead_event_department_assignments' => SyncedTable::EventDepartmentAssignments,
        'department_lead_shifts' => SyncedTable::Shifts,
        'department_lead_shift_assignments' => SyncedTable::ShiftAssignments,
        'department_lead_attendance' => SyncedTable::AttendanceRecords,
        'department_lead_equipment_checkouts' => SyncedTable::EquipmentCheckouts,
        'department_lead_deployments' => SyncedTable::Deployments,
        'department_lead_current_deployments' => SyncedTable::CurrentDeploymentAssignments,
        'department_lead_policy_documents' => SyncedTable::PolicyDocuments,
        'department_lead_procedure_documents' => SyncedTable::ProcedureDocuments,
        'department_lead_document_fragments' => SyncedTable::DocumentFragments,
        'department_lead_document_fragment_references' => SyncedTable::DocumentFragmentReferences,
    ];

    /**
     * The synced table this section projects, or null when nothing declares one.
     */
    public static function tableFor(string $section): ?SyncedTable
    {
        return self::SECTIONS[$section] ?? null;
    }

    /**
     * Every declared section, in declaration order.
     *
     * @return array<string, SyncedTable>
     */
    public static function all(): array
    {
        return self::SECTIONS;
    }

    /**
     * The sections declared against one table.
     *
     * @return list<string>
     */
    public static function sectionsFor(SyncedTable $table): array
    {
        return array_values(array_keys(array_filter(
            self::SECTIONS,
            static fn (SyncedTable $declared): bool => $declared === $table,
        )));
    }
}
