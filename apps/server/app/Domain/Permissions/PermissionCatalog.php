<?php

namespace App\Domain\Permissions;

use App\Models\PermissionRole;

/**
 * Canonical, source-of-truth definition of the Meridian Alpha 1 permission
 * catalog: effective roles, registered permission capabilities, and the
 * mapping between them.
 *
 * Source references:
 * - Technical spec section 15.1 (effective permission levels) and 15.2 (scopes).
 * - Technical spec section 16.2 (IC role capabilities).
 * - Requirements ORG-015 (organizers do not get all incidents/field reports)
 *   and ORG-016 (organizers may view all published policy/procedure documents).
 * - Requirement ORG-002 / M11.12 (organizers manage organization departments).
 * - Requirements VOL-001 through VOL-006 / M11.14 (organizers manage staff
 *   intake and department lead selection).
 */
final class PermissionCatalog
{
    public const ROLE_STAFF = 'staff';

    public const ROLE_SHIFT_LEAD = 'shift_lead';

    public const ROLE_DEPARTMENT_LEAD = 'department_lead';

    public const ROLE_DEPARTMENT_LOGISTICS = 'department_logistics';

    public const ROLE_DEPARTMENT_OPERATIONS = 'department_operations';

    public const ROLE_DEPARTMENT_ADMINISTRATION = 'department_administration';

    public const ROLE_DEPARTMENT_PLANNING = 'department_planning';

    public const ROLE_IC_LEAD = 'ic_lead';

    public const ROLE_IC_OPERATOR = 'ic_operator';

    public const ROLE_IC_VIEWER = 'ic_viewer';

    public const ROLE_ORGANIZER = 'organizer';

    public const ROLE_LEAD_ORGANIZER = 'lead_organizer';

    public const ROLE_GOD_MODE = 'god_mode';

    public const PERMISSION_INCIDENTS_VIEW = 'incidents.view';

    public const PERMISSION_INCIDENTS_CREATE = 'incidents.create';

    public const PERMISSION_INCIDENTS_UPDATE = 'incidents.update';

    public const PERMISSION_INCIDENTS_ADD_NOTE = 'incidents.add_note';

    public const PERMISSION_INCIDENTS_CLOSE = 'incidents.close';

    public const PERMISSION_INCIDENTS_REOPEN = 'incidents.reopen';

    public const PERMISSION_INCIDENTS_LINK_FIELD_REPORT = 'incidents.link_field_report';

    public const PERMISSION_INCIDENTS_PRINT = 'incidents.print';

    public const PERMISSION_FIELD_REPORTS_VIEW_EVENT = 'field_reports.view_event';

    public const PERMISSION_FIELD_REPORTS_DOWNLOAD_PHOTO = 'field_reports.download_photo';

    public const PERMISSION_POLICIES_VIEW_PUBLISHED = 'policies.view_published';

    public const PERMISSION_ORGANIZATION_DEPARTMENTS_MANAGE = 'organization.departments.manage';

    public const PERMISSION_ORGANIZATION_STAFF_MANAGE = 'organization.staff.manage';

    public const PERMISSION_DEPARTMENT_PRESENCE_MANAGE = 'department.presence.manage';

    public const PERMISSION_DEPARTMENT_ATTENDANCE_MANAGE = 'department.attendance.manage';

    public const PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE = 'department.equipment.manage';

    public const PERMISSION_DEPARTMENT_DEPLOYMENTS_ASSIGN = 'department.deployments.assign';

    public const PERMISSION_DEPARTMENT_SCHEDULE_MANAGE = 'department.schedule.manage';

    public const PERMISSION_DEPARTMENT_ADMINISTER = 'department.administer';

    public const PERMISSION_DEPARTMENT_TRAININGS_MANAGE = 'department.trainings.manage';

    public const PERMISSION_ORGANIZATION_BRANDING_MANAGE = 'organization.branding.manage';

    public const PERMISSION_DEPARTMENT_BRANDING_MANAGE = 'department.branding.manage';

    public const PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT = 'reports.credential_eligibility.export';

    public const PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT = 'reports.shift_roster.export';

    public const PERMISSION_REPORTS_STAFF_CONTACT_EXPORT = 'reports.staff_contact.export';

    public const PERMISSION_REPORTS_HOURS_WORKED_EXPORT = 'reports.hours_worked.export';

    public const PERMISSION_REPORTS_CREDITS_EARNED_EXPORT = 'reports.credits_earned.export';

    /**
     * Canonical effective roles keyed by code (technical spec section 15.1)
     * with their authority scope (technical spec section 15.2).
     *
     * @return array<string, array{name: string, scope_type: string}>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_STAFF => ['name' => 'Staff', 'scope_type' => PermissionRole::SCOPE_ORGANIZATION],
            self::ROLE_SHIFT_LEAD => ['name' => 'Shift Lead', 'scope_type' => PermissionRole::SCOPE_TEAM],
            self::ROLE_DEPARTMENT_LEAD => ['name' => 'Department Lead', 'scope_type' => PermissionRole::SCOPE_DEPARTMENT],
            self::ROLE_DEPARTMENT_LOGISTICS => ['name' => 'Department Logistics', 'scope_type' => PermissionRole::SCOPE_DEPARTMENT],
            self::ROLE_DEPARTMENT_OPERATIONS => ['name' => 'Department Operations', 'scope_type' => PermissionRole::SCOPE_DEPARTMENT],
            self::ROLE_DEPARTMENT_ADMINISTRATION => ['name' => 'Department Administration', 'scope_type' => PermissionRole::SCOPE_DEPARTMENT],
            self::ROLE_DEPARTMENT_PLANNING => ['name' => 'Department Planning', 'scope_type' => PermissionRole::SCOPE_DEPARTMENT],
            self::ROLE_IC_LEAD => ['name' => 'Incident Command Lead', 'scope_type' => PermissionRole::SCOPE_EVENT],
            self::ROLE_IC_OPERATOR => ['name' => 'Incident Command Operator', 'scope_type' => PermissionRole::SCOPE_EVENT],
            self::ROLE_IC_VIEWER => ['name' => 'Incident Command Viewer', 'scope_type' => PermissionRole::SCOPE_EVENT],
            self::ROLE_ORGANIZER => ['name' => 'Organizer', 'scope_type' => PermissionRole::SCOPE_ORGANIZATION],
            self::ROLE_LEAD_ORGANIZER => ['name' => 'Lead Organizer', 'scope_type' => PermissionRole::SCOPE_ORGANIZATION],
            self::ROLE_GOD_MODE => ['name' => 'God Mode', 'scope_type' => PermissionRole::SCOPE_NODE],
        ];
    }

    /**
     * Registered permission capabilities keyed by code.
     *
     * Only capabilities defined by governing documents for this task are
     * registered here. Finer-grained capabilities for staff, shift lead,
     * department lead, and god mode are added by their owning milestones.
     *
     * @return array<string, string>
     */
    public static function permissions(): array
    {
        return [
            self::PERMISSION_INCIDENTS_VIEW => 'View incidents.',
            self::PERMISSION_INCIDENTS_CREATE => 'Create incidents.',
            self::PERMISSION_INCIDENTS_UPDATE => 'Update incident fields.',
            self::PERMISSION_INCIDENTS_ADD_NOTE => 'Add incident notes.',
            self::PERMISSION_INCIDENTS_CLOSE => 'Close incidents.',
            self::PERMISSION_INCIDENTS_REOPEN => 'Reopen incidents.',
            self::PERMISSION_INCIDENTS_LINK_FIELD_REPORT => 'Link and unlink field reports from incidents.',
            self::PERMISSION_INCIDENTS_PRINT => 'Print incidents to PDF.',
            self::PERMISSION_FIELD_REPORTS_VIEW_EVENT => 'View all field reports for the event.',
            self::PERMISSION_FIELD_REPORTS_DOWNLOAD_PHOTO => 'Download field report photos.',
            self::PERMISSION_POLICIES_VIEW_PUBLISHED => 'View all published policy and procedure documents in the organization.',
            self::PERMISSION_ORGANIZATION_DEPARTMENTS_MANAGE => 'Create, edit, archive, restore, and list organization departments.',
            self::PERMISSION_ORGANIZATION_STAFF_MANAGE => 'Add, invite, list, and assign organization staff and department leads.',
            self::PERMISSION_DEPARTMENT_PRESENCE_MANAGE => 'Mark eligible department staff on-site or off-site.',
            self::PERMISSION_DEPARTMENT_ATTENDANCE_MANAGE => 'Check department staff in and out of shifts.',
            self::PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE => 'Check department equipment in and out.',
            self::PERMISSION_DEPARTMENT_DEPLOYMENTS_ASSIGN => 'Assign current or planned shift deployments.',
            self::PERMISSION_DEPARTMENT_SCHEDULE_MANAGE => 'View identity-free Planning Table aggregates comparing plan versus actual.',
            self::PERMISSION_DEPARTMENT_ADMINISTER => 'Administer permitted department details and teams (team membership assignment remains a separate workflow).',
            self::PERMISSION_DEPARTMENT_TRAININGS_MANAGE => 'Create and maintain department trainings, prerequisites, rosters, and completion records.',
            self::PERMISSION_ORGANIZATION_BRANDING_MANAGE => 'Edit the organization branding profile: display name, palette, logo assets, and the department override switch.',
            self::PERMISSION_DEPARTMENT_BRANDING_MANAGE => 'Edit the department branding profile: logo, accent color, and surface background color.',
            self::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT => 'Export event credential eligibility; organizers export the whole event, department roles export their own department.',
            self::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT => 'Export the event shift roster without phone numbers or emergency contacts; organizers export the whole event, department roles export their own department.',
            self::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT => 'Export the staff contact list; organizers export the whole event without emergency contacts, department roles export their own department with them.',
            self::PERMISSION_REPORTS_HOURS_WORKED_EXPORT => 'Export actual hours worked with the scheduled window and correction state; organizers export the whole event, department roles export their own department.',
            self::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT => 'Export credits earned with the calculation basis each number was frozen at; organizers export the whole event, department roles export their own department.',
        ];
    }

    /**
     * Mapping of role code to the permission codes it grants.
     *
     * IC mappings follow technical spec section 16.2, with `incidents.print`
     * granted only to `ic_lead` per INC-015 / requirements section 4.10.
     * Organizer mappings follow ORG-016 and ORG-002/M11.12 while honouring
     * ORG-015 by deliberately excluding incident and field report capabilities.
     * M11.14 adds organization.staff.manage to organizer roles for product-path
     * staff intake and department lead selection without widening IMS or Field
     * Report access.
     * Department lead and department_administration share department.administer
     * for M11.13 department self-administration (UI contract 12.4 / tech spec 15.2).
     * M11.16 adds department.trainings.manage to department lead,
     * department_administration, and organizer roles for product-path training
     * creation, prerequisite/expiration setup, rosters, and completion
     * recording/import (TRAIN-001 through TRAIN-006).
     * M13.1 adds reports.credential_eligibility.export, M13.2 adds
     * reports.shift_roster.export, M13.3 adds reports.staff_contact.export,
     * M13.4 adds reports.hours_worked.export, and M13.6 adds
     * reports.credits_earned.export to the two organizer roles and to
     * department lead/department_administration, which is the split REPORT-006
     * and REPORT-007 draw: organizers export event-wide, department leads export
     * their own department. Each report carries its own permission so a later
     * export cannot inherit authority it was never granted. The permission
     * grants the export, not its emergency contact columns: those follow
     * REPORT-009/REPORT-010 from the resolved scope, so an organizer holding
     * reports.staff_contact.export still gets a file without them.
     * Reading hours is not correcting them: reports.hours_worked.export is a
     * read of what attendance already recorded, and the authority to change a
     * record stays with the attendance managers HOURS-007 names. The same holds
     * one step further on: reports.credits_earned.export reads a frozen ledger
     * (CREDIT-004), and nothing about holding it lets a role start a
     * calculation run or reprice one that already happened.
     * M15A.6/M15A.7 add organization.branding.manage to the two organizer roles
     * and department.branding.manage to department lead and
     * department_administration, which is exactly the split BRAND-019 draws:
     * organizers own the organization palette, departments own only their own
     * logo, accent, and surface background.
     * Roles without an entry intentionally have no catalog permissions yet and
     * are populated by their owning milestones.
     *
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            self::ROLE_DEPARTMENT_LEAD => [
                self::PERMISSION_DEPARTMENT_ADMINISTER,
                self::PERMISSION_DEPARTMENT_TRAININGS_MANAGE,
                self::PERMISSION_DEPARTMENT_BRANDING_MANAGE,
                self::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
                self::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT,
                self::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT,
                self::PERMISSION_REPORTS_HOURS_WORKED_EXPORT,
                self::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
            ],
            self::ROLE_IC_LEAD => [
                self::PERMISSION_INCIDENTS_VIEW,
                self::PERMISSION_INCIDENTS_CREATE,
                self::PERMISSION_INCIDENTS_UPDATE,
                self::PERMISSION_INCIDENTS_ADD_NOTE,
                self::PERMISSION_INCIDENTS_CLOSE,
                self::PERMISSION_INCIDENTS_REOPEN,
                self::PERMISSION_INCIDENTS_LINK_FIELD_REPORT,
                self::PERMISSION_INCIDENTS_PRINT,
                self::PERMISSION_FIELD_REPORTS_VIEW_EVENT,
                self::PERMISSION_FIELD_REPORTS_DOWNLOAD_PHOTO,
            ],
            self::ROLE_IC_OPERATOR => [
                self::PERMISSION_INCIDENTS_VIEW,
                self::PERMISSION_INCIDENTS_CREATE,
                self::PERMISSION_INCIDENTS_UPDATE,
                self::PERMISSION_INCIDENTS_ADD_NOTE,
                self::PERMISSION_INCIDENTS_CLOSE,
                self::PERMISSION_INCIDENTS_REOPEN,
                self::PERMISSION_INCIDENTS_LINK_FIELD_REPORT,
                self::PERMISSION_FIELD_REPORTS_VIEW_EVENT,
            ],
            self::ROLE_IC_VIEWER => [
                self::PERMISSION_INCIDENTS_VIEW,
                self::PERMISSION_FIELD_REPORTS_VIEW_EVENT,
            ],
            self::ROLE_DEPARTMENT_LOGISTICS => [
                self::PERMISSION_DEPARTMENT_PRESENCE_MANAGE,
                self::PERMISSION_DEPARTMENT_ATTENDANCE_MANAGE,
                self::PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE,
            ],
            self::ROLE_DEPARTMENT_OPERATIONS => [
                self::PERMISSION_DEPARTMENT_DEPLOYMENTS_ASSIGN,
            ],
            self::ROLE_DEPARTMENT_ADMINISTRATION => [
                self::PERMISSION_DEPARTMENT_ADMINISTER,
                self::PERMISSION_DEPARTMENT_TRAININGS_MANAGE,
                self::PERMISSION_DEPARTMENT_BRANDING_MANAGE,
                self::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
                self::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT,
                self::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT,
                self::PERMISSION_REPORTS_HOURS_WORKED_EXPORT,
                self::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
            ],
            self::ROLE_DEPARTMENT_PLANNING => [
                self::PERMISSION_DEPARTMENT_SCHEDULE_MANAGE,
            ],
            self::ROLE_ORGANIZER => [
                self::PERMISSION_POLICIES_VIEW_PUBLISHED,
                self::PERMISSION_ORGANIZATION_DEPARTMENTS_MANAGE,
                self::PERMISSION_ORGANIZATION_STAFF_MANAGE,
                self::PERMISSION_DEPARTMENT_TRAININGS_MANAGE,
                self::PERMISSION_ORGANIZATION_BRANDING_MANAGE,
                self::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
                self::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT,
                self::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT,
                self::PERMISSION_REPORTS_HOURS_WORKED_EXPORT,
                self::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
            ],
            self::ROLE_LEAD_ORGANIZER => [
                self::PERMISSION_POLICIES_VIEW_PUBLISHED,
                self::PERMISSION_ORGANIZATION_DEPARTMENTS_MANAGE,
                self::PERMISSION_ORGANIZATION_STAFF_MANAGE,
                self::PERMISSION_DEPARTMENT_TRAININGS_MANAGE,
                self::PERMISSION_ORGANIZATION_BRANDING_MANAGE,
                self::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
                self::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT,
                self::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT,
                self::PERMISSION_REPORTS_HOURS_WORKED_EXPORT,
                self::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
            ],
        ];
    }

    /**
     * Whether the given effective role code includes a catalog permission.
     */
    public static function roleHasPermission(string $roleCode, string $permissionCode): bool
    {
        return in_array($permissionCode, self::rolePermissions()[$roleCode] ?? [], true);
    }
}
