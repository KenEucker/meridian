<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * The Alpha 1 dashboard widget inventory (UI contract 13.1 through 13.6;
 * dashboard widget spec 6; M18.28).
 *
 * Every row of the contract's six tables is here, in the contract's own order,
 * including the five that cannot be compiled yet. That is deliberate: the
 * inventory is the thing the contract specifies, and a catalogue that silently
 * omitted a widget would make a gap indistinguishable from a decision. The five
 * name the task that will answer them and are never compiled into a response,
 * because widget spec 4 says a widget that cannot answer its required anatomy —
 * data source, attention, quiet state — should not ship, and a card reporting
 * zero because nothing can produce a number is worse than no card.
 *
 * The five, and why:
 *
 *  - `staff.briefing` and `ic.briefing` need Notes and The Briefing, which is
 *    Milestone 15. There is no `notes` table on this node.
 *  - `dept.event_map` and `kiosk.event_map` need Event Geography and Maps, which
 *    is Milestone 14, and M14.9 owns these two widgets by name.
 *  - `org.planning_tasks` has no domain anywhere in Alpha 1. It appears in the
 *    contract and in widget spec 6 and in no requirement, so there is no record
 *    a planning task could be read from. It waits for one to be specified rather
 *    than for a task that exists.
 *
 * The organizer group's own rule is enforced by construction rather than by a
 * filter: UI contract 13.4 says organizer widgets must not surface IMS
 * incidents, restricted Field Reports, active incident counts, or incident
 * priority alerts without IC authority, and the way to keep that promise is for
 * the organizer group to contain no widget that reads an incident. Incident data
 * lives in 13.5 and reaches a person through IC standing, which
 * `DashboardAudience` resolves separately. An organizer who also holds IC
 * standing gets the IC group as an IC user, not as an organizer.
 */
final class DashboardCatalog
{
    /**
     * The whole inventory, in contract order.
     *
     * @return list<DashboardWidgetDefinition>
     */
    public static function definitions(): array
    {
        return [
            ...self::staffWidgets(),
            ...self::departmentLeadWidgets(),
            ...self::departmentOperationsWidgets(),
            ...self::organizerWidgets(),
            ...self::incidentCommandWidgets(),
            ...self::kioskWidgets(),
        ];
    }

    /**
     * The inventory for one group, in contract order.
     *
     * @return list<DashboardWidgetDefinition>
     */
    public static function forGroup(DashboardWidgetGroup $group): array
    {
        return array_values(array_filter(
            self::definitions(),
            fn (DashboardWidgetDefinition $definition): bool => $definition->group === $group,
        ));
    }

    public static function definition(string $id): ?DashboardWidgetDefinition
    {
        foreach (self::definitions() as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * The widgets a compiler is expected to be able to answer.
     *
     * @return list<DashboardWidgetDefinition>
     */
    public static function answerable(DashboardWidgetGroup $group): array
    {
        return array_values(array_filter(
            self::forGroup($group),
            fn (DashboardWidgetDefinition $definition): bool => $definition->isAnswerable()
                && $definition->evaluation === DashboardWidgetEvaluation::Node,
        ));
    }

    /**
     * The inventory as a surface reads it: what exists, what is deferred, and
     * what the device answers for itself.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        return array_map(
            fn (DashboardWidgetDefinition $definition): array => [
                ...$definition->describe(),
                'permission' => $definition->permission,
                'evaluation' => $definition->evaluation->value,
                'deferred_to' => $definition->deferredTo,
            ],
            self::definitions(),
        );
    }

    /**
     * UI contract 13.1.
     *
     * `staff.quiet_state` is the one widget in the inventory that is a statement
     * about the other widgets rather than about a record. It is compiled only
     * when every other staff widget went quiet, which is what makes it
     * reassurance rather than decoration.
     *
     * @return list<DashboardWidgetDefinition>
     */
    private static function staffWidgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                id: 'staff.current_shift',
                group: DashboardWidgetGroup::Staff,
                title: 'Current Shift',
                scope: 'user/event',
                permission: 'assigned or checked-in staff',
                quietState: 'No current shift',
                actionLabel: 'View shift',
                actionSurface: 'staff.shifts',
            ),
            new DashboardWidgetDefinition(
                id: 'staff.upcoming_shifts',
                group: DashboardWidgetGroup::Staff,
                title: 'Upcoming Shifts',
                scope: 'user/event',
                permission: 'authenticated staff',
                quietState: 'No upcoming shifts',
                actionLabel: 'View my shifts',
                actionSurface: 'staff.shifts',
            ),
            new DashboardWidgetDefinition(
                id: 'staff.assigned_departments',
                group: DashboardWidgetGroup::Staff,
                title: 'Assigned Departments',
                scope: 'user/org/event',
                permission: 'authenticated staff',
                quietState: 'No assigned departments',
                actionLabel: 'View departments',
                actionSurface: 'context.departments',
            ),
            new DashboardWidgetDefinition(
                id: 'staff.shift_alerts',
                group: DashboardWidgetGroup::Staff,
                title: 'Shift Alerts',
                scope: 'user/event',
                permission: 'authenticated staff',
                quietState: 'No shift alerts',
                actionLabel: 'View alert source',
                actionSurface: 'staff.shifts',
            ),
            new DashboardWidgetDefinition(
                id: 'staff.document_acknowledgments',
                group: DashboardWidgetGroup::Staff,
                title: 'Documents to Acknowledge',
                scope: 'user/org/department',
                permission: 'authenticated staff',
                quietState: 'No documents need acknowledgment',
                actionLabel: 'Review documents',
                actionSurface: 'staff.document-acknowledgments',
            ),
            new DashboardWidgetDefinition(
                id: 'staff.briefing',
                group: DashboardWidgetGroup::Staff,
                title: 'The Briefing',
                scope: 'user/event',
                permission: 'approved event staff',
                quietState: 'No Briefing items',
                actionLabel: 'Open Briefing hub',
                actionSurface: 'briefing.hub',
                deferredTo: 'M15.6',
            ),
            new DashboardWidgetDefinition(
                id: 'staff.quiet_state',
                group: DashboardWidgetGroup::Staff,
                title: 'Nothing Needs Action',
                scope: 'user/event',
                permission: 'authenticated staff',
                quietState: 'Nothing needs your attention right now.',
            ),
        ];
    }

    /**
     * UI contract 13.2.
     *
     * Two of these belong to department logistics rather than to the department
     * lead, which is why the group is gated per widget: the contract grants
     * check-in status and equipment returns to `department_logistics`, and a
     * lead who does not also hold logistics reads neither.
     *
     * @return list<DashboardWidgetDefinition>
     */
    private static function departmentLeadWidgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                id: 'dept.coverage_issues',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Coverage Issues',
                scope: 'department/event',
                permission: 'department lead',
                quietState: 'All scheduled shifts covered',
                actionLabel: 'Open shifts',
                actionSurface: 'department.shifts',
            ),
            new DashboardWidgetDefinition(
                id: 'dept.shift_readiness',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Shift Readiness',
                scope: 'department/event',
                permission: 'department lead',
                quietState: 'Department shifts ready',
                actionLabel: 'Review shifts',
                actionSurface: 'department.shifts',
            ),
            new DashboardWidgetDefinition(
                id: 'dept.checkin_status',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Check-in Status',
                scope: 'department/event',
                permission: 'department logistics',
                quietState: 'No check-in issues',
                actionLabel: 'Open Logistics Window',
                actionSurface: 'department.logistics',
            ),
            new DashboardWidgetDefinition(
                id: 'dept.training_readiness',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Training Readiness',
                scope: 'department/event',
                permission: 'department lead',
                quietState: 'Required trainings complete',
                actionLabel: 'Review trainings',
                actionSurface: 'department.trainings',
            ),
            new DashboardWidgetDefinition(
                id: 'dept.policy_readiness',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Policy Readiness',
                scope: 'department',
                permission: 'department lead',
                quietState: 'Department documents current',
                actionLabel: 'Review documents',
                actionSurface: 'department.documents',
            ),
            new DashboardWidgetDefinition(
                id: 'dept.equipment_returns',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Equipment Returns',
                scope: 'department/event',
                permission: 'department logistics',
                quietState: 'No equipment returns pending',
                actionLabel: 'Open Logistics Window',
                actionSurface: 'department.logistics',
            ),
            new DashboardWidgetDefinition(
                id: 'dept.event_map',
                group: DashboardWidgetGroup::DepartmentLead,
                title: 'Event Map',
                scope: 'department/event',
                permission: 'department lead with map view permission; Placement dept lead gets management access',
                quietState: 'No published map',
                actionLabel: 'Open event map',
                actionSurface: 'map.view',
                deferredTo: 'M14.9',
            ),
        ];
    }

    /**
     * UI contract 13.3.
     *
     * These are the widgets about the shift in front of somebody rather than
     * about the department, so each is compiled against the department's current
     * shift — the one running now, or the next one when none is.
     *
     * @return list<DashboardWidgetDefinition>
     */
    private static function departmentOperationsWidgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                id: 'shift.current_assignments',
                group: DashboardWidgetGroup::DepartmentOperations,
                title: 'Current Assignments',
                scope: 'shift/department/event',
                permission: 'operational visibility',
                quietState: 'No current assignments',
                actionLabel: 'Open Department Overview',
                actionSurface: 'department.overview',
            ),
            new DashboardWidgetDefinition(
                id: 'shift.late_missing',
                group: DashboardWidgetGroup::DepartmentOperations,
                title: 'Late or Missing Staff',
                scope: 'shift/department/event',
                permission: 'department logistics',
                quietState: 'No late or missing staff',
                actionLabel: 'Review check-in',
                actionSurface: 'department.logistics',
            ),
            new DashboardWidgetDefinition(
                id: 'shift.deployment_needs',
                group: DashboardWidgetGroup::DepartmentOperations,
                title: 'Deployment Needs',
                scope: 'shift/department/event',
                permission: 'department operations',
                quietState: 'Deployments look okay',
                actionLabel: 'Open Operations Center',
                actionSurface: 'department.operations',
            ),
            new DashboardWidgetDefinition(
                id: 'shift.equipment_status',
                group: DashboardWidgetGroup::DepartmentOperations,
                title: 'Equipment Status',
                scope: 'shift/department/event',
                permission: 'department logistics',
                quietState: 'Equipment accounted for',
                actionLabel: 'Open Logistics Window',
                actionSurface: 'department.logistics',
            ),
        ];
    }

    /**
     * UI contract 13.4.
     *
     * No widget here reads an incident, a Field Report, or an incident count.
     * That is the contract's exclusion kept by construction rather than by a
     * filter somebody could forget to apply.
     *
     * @return list<DashboardWidgetDefinition>
     */
    private static function organizerWidgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                id: 'org.event_readiness',
                group: DashboardWidgetGroup::Organizer,
                title: 'Event Readiness',
                scope: 'org/event',
                permission: 'organizer',
                quietState: 'Event readiness looks okay',
                actionLabel: 'Review readiness',
                actionSurface: 'organizer.events',
            ),
            new DashboardWidgetDefinition(
                id: 'org.cross_dept_coverage',
                group: DashboardWidgetGroup::Organizer,
                title: 'Cross-Department Coverage',
                scope: 'org/event',
                permission: 'organizer',
                quietState: 'No cross-department coverage issues',
                actionLabel: 'Review coverage',
                actionSurface: 'organizer.departments',
            ),
            new DashboardWidgetDefinition(
                id: 'org.application_review',
                group: DashboardWidgetGroup::Organizer,
                title: 'Applications to Review',
                scope: 'org/event',
                permission: 'organizer',
                quietState: 'No applications awaiting review',
                actionLabel: 'Review applications',
                actionSurface: 'organizer.applications',
            ),
            new DashboardWidgetDefinition(
                id: 'org.policy_readiness',
                group: DashboardWidgetGroup::Organizer,
                title: 'Policy Readiness',
                scope: 'org/event',
                permission: 'organizer',
                quietState: 'Required documents current',
                actionLabel: 'Review policies',
                actionSurface: 'organizer.policy-documents',
            ),
            new DashboardWidgetDefinition(
                id: 'org.planning_tasks',
                group: DashboardWidgetGroup::Organizer,
                title: 'Planning Tasks',
                scope: 'org/event',
                permission: 'organizer',
                quietState: 'No planning tasks due',
                actionLabel: 'View tasks',
                deferredTo: 'a specified planning task record',
            ),
            new DashboardWidgetDefinition(
                id: 'org.operations_window',
                group: DashboardWidgetGroup::Organizer,
                title: 'Operations Window',
                scope: 'org/event',
                permission: 'organizer',
                quietState: 'Event outside operations window',
                actionLabel: 'View event',
                actionSurface: 'organizer.events',
            ),
        ];
    }

    /**
     * UI contract 13.5.
     *
     * Every widget is scoped to the event's Incident Command department and
     * permitted by IC standing in it, which `EffectiveRoleResolver` already
     * resolves: an IC grant on a team whose department is not the event's IC
     * department is not an IC role for this event at all.
     *
     * @return list<DashboardWidgetDefinition>
     */
    private static function incidentCommandWidgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                id: 'ic.active_incidents',
                group: DashboardWidgetGroup::IncidentCommand,
                title: 'Active Incidents',
                scope: 'event/IC department',
                permission: 'IC viewer/operator/lead',
                quietState: 'No active incidents',
                actionLabel: 'Open incidents',
                actionSurface: 'ims.incidents',
            ),
            new DashboardWidgetDefinition(
                id: 'ic.serious_incidents',
                group: DashboardWidgetGroup::IncidentCommand,
                title: 'Serious Incidents',
                scope: 'event/IC department',
                permission: 'IC viewer/operator/lead',
                quietState: 'No serious incidents',
                actionLabel: 'Review incidents',
                actionSurface: 'ims.incidents',
            ),
            new DashboardWidgetDefinition(
                id: 'ic.on_scene',
                group: DashboardWidgetGroup::IncidentCommand,
                title: 'On Scene',
                scope: 'event/IC department',
                permission: 'IC viewer/operator/lead',
                quietState: 'No incidents on scene',
                actionLabel: 'Open incidents',
                actionSurface: 'ims.incidents',
            ),
            new DashboardWidgetDefinition(
                id: 'ic.monitoring',
                group: DashboardWidgetGroup::IncidentCommand,
                title: 'Monitoring',
                scope: 'event/IC department',
                permission: 'IC viewer/operator/lead',
                quietState: 'No monitoring incidents',
                actionLabel: 'Open incidents',
                actionSurface: 'ims.incidents',
            ),
            new DashboardWidgetDefinition(
                id: 'ic.unresolved_field_reports',
                group: DashboardWidgetGroup::IncidentCommand,
                title: 'Field Reports to Review',
                scope: 'event/IC department',
                permission: 'IC viewer/operator/lead',
                quietState: 'No Field Reports awaiting IC review',
                actionLabel: 'Review Field Reports',
                actionSurface: 'ims.field-reports',
            ),
            new DashboardWidgetDefinition(
                id: 'ic.briefing',
                group: DashboardWidgetGroup::IncidentCommand,
                title: 'The Briefing',
                scope: 'event/IC department',
                permission: 'IC viewer/operator/lead',
                quietState: 'Briefing quiet',
                actionLabel: 'Open Briefing hub',
                actionSurface: 'briefing.hub',
                deferredTo: 'M15.6',
            ),
        ];
    }

    /**
     * UI contract 13.6.
     *
     * The trusted workstation and the person signed in at it are two different
     * things (widget spec 9), so the group is opened by the workstation and each
     * widget inside it is still gated on what that person may do.
     *
     * @return list<DashboardWidgetDefinition>
     */
    private static function kioskWidgets(): array
    {
        return [
            new DashboardWidgetDefinition(
                id: 'kiosk.current_tasks',
                group: DashboardWidgetGroup::Kiosk,
                title: 'Current Operational Tasks',
                scope: 'event/kiosk',
                permission: 'trusted workstation + user permissions',
                quietState: 'No current kiosk tasks',
                actionLabel: 'Open task',
                actionSurface: 'kiosk.home',
            ),
            new DashboardWidgetDefinition(
                id: 'kiosk.staff_checkin',
                group: DashboardWidgetGroup::Kiosk,
                title: 'Staff-Mediated Check-in',
                scope: 'event/department',
                permission: 'shift/department lead',
                quietState: 'No check-ins pending',
                actionLabel: 'Start check-in',
                actionSurface: 'department.logistics',
            ),
            new DashboardWidgetDefinition(
                id: 'kiosk.equipment_returns',
                group: DashboardWidgetGroup::Kiosk,
                title: 'Equipment Returns',
                scope: 'event/department',
                permission: 'shift/department lead',
                quietState: 'No returns pending',
                actionLabel: 'Open returns',
                actionSurface: 'department.logistics',
            ),
            new DashboardWidgetDefinition(
                id: 'kiosk.node_status',
                group: DashboardWidgetGroup::Kiosk,
                title: 'Local Node Status',
                scope: 'kiosk/event',
                permission: 'trusted workstation',
                quietState: 'Local node reachable',
                actionLabel: 'View status if permitted',
                actionSurface: 'readiness',
                evaluation: DashboardWidgetEvaluation::Device,
            ),
            new DashboardWidgetDefinition(
                id: 'kiosk.switch_user',
                group: DashboardWidgetGroup::Kiosk,
                title: 'Current User',
                scope: 'kiosk',
                permission: 'trusted workstation',
                quietState: 'User visible',
                actionLabel: 'Switch user',
                actionSurface: 'kiosk.switch-user',
                evaluation: DashboardWidgetEvaluation::Device,
            ),
            new DashboardWidgetDefinition(
                id: 'kiosk.event_map',
                group: DashboardWidgetGroup::Kiosk,
                title: 'Event Map',
                scope: 'event/kiosk',
                permission: 'trusted workstation + map view permission',
                quietState: 'No published map',
                actionLabel: 'Open event map',
                actionSurface: 'map.view',
                deferredTo: 'M14.9',
            ),
        ];
    }
}
