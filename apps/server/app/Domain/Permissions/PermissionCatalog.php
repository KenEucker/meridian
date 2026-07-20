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

    public const PERMISSION_FIELD_REPORTS_VIEW_EVENT = 'field_reports.view_event';

    public const PERMISSION_FIELD_REPORTS_DOWNLOAD_PHOTO = 'field_reports.download_photo';

    public const PERMISSION_POLICIES_VIEW_PUBLISHED = 'policies.view_published';

    public const PERMISSION_DEPARTMENT_PRESENCE_MANAGE = 'department.presence.manage';

    public const PERMISSION_DEPARTMENT_ATTENDANCE_MANAGE = 'department.attendance.manage';

    public const PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE = 'department.equipment.manage';

    public const PERMISSION_DEPARTMENT_DEPLOYMENTS_ASSIGN = 'department.deployments.assign';

    public const PERMISSION_DEPARTMENT_SCHEDULE_MANAGE = 'department.schedule.manage';

    public const PERMISSION_DEPARTMENT_ADMINISTER = 'department.administer';

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
            self::PERMISSION_FIELD_REPORTS_VIEW_EVENT => 'View all field reports for the event.',
            self::PERMISSION_FIELD_REPORTS_DOWNLOAD_PHOTO => 'Download field report photos.',
            self::PERMISSION_POLICIES_VIEW_PUBLISHED => 'View all published policy and procedure documents in the organization.',
            self::PERMISSION_DEPARTMENT_PRESENCE_MANAGE => 'Mark eligible department staff on-site or off-site.',
            self::PERMISSION_DEPARTMENT_ATTENDANCE_MANAGE => 'Check department staff in and out of shifts.',
            self::PERMISSION_DEPARTMENT_EQUIPMENT_MANAGE => 'Check department equipment in and out.',
            self::PERMISSION_DEPARTMENT_DEPLOYMENTS_ASSIGN => 'Assign current or planned shift deployments.',
            self::PERMISSION_DEPARTMENT_SCHEDULE_MANAGE => 'View identity-free Planning Table aggregates comparing plan versus actual.',
            self::PERMISSION_DEPARTMENT_ADMINISTER => 'Administer department settings and team membership.',
        ];
    }

    /**
     * Mapping of role code to the permission codes it grants.
     *
     * IC mappings follow technical spec section 16.2. Organizer mappings follow
     * ORG-016 while honouring ORG-015 by deliberately excluding incident and
     * field report capabilities. Roles without an entry intentionally have no
     * catalog permissions yet and are populated by their owning milestones.
     *
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            self::ROLE_IC_LEAD => [
                self::PERMISSION_INCIDENTS_VIEW,
                self::PERMISSION_INCIDENTS_CREATE,
                self::PERMISSION_INCIDENTS_UPDATE,
                self::PERMISSION_INCIDENTS_ADD_NOTE,
                self::PERMISSION_INCIDENTS_CLOSE,
                self::PERMISSION_INCIDENTS_REOPEN,
                self::PERMISSION_INCIDENTS_LINK_FIELD_REPORT,
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
            ],
            self::ROLE_DEPARTMENT_PLANNING => [
                self::PERMISSION_DEPARTMENT_SCHEDULE_MANAGE,
            ],
            self::ROLE_ORGANIZER => [
                self::PERMISSION_POLICIES_VIEW_PUBLISHED,
            ],
            self::ROLE_LEAD_ORGANIZER => [
                self::PERMISSION_POLICIES_VIEW_PUBLISHED,
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
