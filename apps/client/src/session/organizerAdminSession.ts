// Who the organizer administration surfaces act as, and which organization they
// act on (M16.14; CLIENT-023; ORG-002; data/API 10.6).
//
// Departments and Staff are the two surfaces that administer an *organization*
// rather than a department, and until this task they resolved that organization
// from a bundled fixture: a development session was installed on entry, and the
// organization id was a constant compiled into the client. Both are gone. The
// organization comes from the session document, and so does the authority.
//
// Two properties are load-bearing:
//
//  1. **The department in hand names the organization.** A user may hold
//     standing in more than one organization, and there is no separate
//     organization switcher; the department switcher is the one control that
//     says which body of work the client is in. Reading `organization_id` off
//     the selected department is therefore the same answer the rest of the
//     client already gives, and it is the answer `workflowLinks` gates the Home
//     cards on.
//  2. **The capability is checked where it was granted.** `organization.*`
//     capabilities arrive on roles scoped to an organization, but the session
//     document attaches those roles to the department they resolved at, so a
//     department-scoped check is a check on the grant itself. Someone who
//     organizes one organization and is ordinary staff in another is refused
//     while working in the second, which is what the server would do.
//
// This is presentation. The server authorizes every request these surfaces make
// and refuses one this module would have allowed (CLIENT-006).

import { computed } from "vue";

import { clientSessionState } from "@/session/clientSession";
import {
  CAPABILITY_ORGANIZATION_CONFIGURATION_MANAGE,
  CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE,
  CAPABILITY_ORGANIZATION_DESIGNATIONS_MANAGE,
  CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE,
  CAPABILITY_ORGANIZATION_STAFF_MANAGE,
} from "@/session/permissionCodes";
import { selectedSessionDepartment } from "@/session/sessionAccess";

/** The organization an administration surface is acting on, and as what. */
export interface OrganizerAdminSession {
  readonly organizationId: string;
  /** The organization's name, for the surface lede. */
  readonly organizationLabel: string;
  /** The roles that carry the authority, named as the server named them. */
  readonly roleLabel: string;
}

function resolveOrganizerAdminSession(
  capability: string,
): OrganizerAdminSession | null {
  const department = selectedSessionDepartment.value;

  if (department === null || department.organizationId === null) {
    return null;
  }

  const granting = department.roles.filter((role) =>
    role.capabilities.includes(capability),
  );

  if (granting.length === 0) {
    return null;
  }

  const organization = clientSessionState.document?.organizations.find(
    (candidate) => candidate.id === department.organizationId,
  );

  const roleNames = [
    ...new Set(
      granting
        .map((role) => role.role_name)
        .filter((name): name is string => typeof name === "string" && name !== ""),
    ),
  ];

  return {
    organizationId: department.organizationId,
    organizationLabel: organization?.name ?? "This organization",
    roleLabel: roleNames.length > 0 ? roleNames.join(", ") : "Organizer",
  };
}

/** The organization whose departments this client may administer, or null. */
export const organizerDepartmentAdminSession = computed(() =>
  resolveOrganizerAdminSession(CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE),
);

/** The organization whose staff this client may administer, or null. */
export const organizerStaffAdminSession = computed(() =>
  resolveOrganizerAdminSession(CAPABILITY_ORGANIZATION_STAFF_MANAGE),
);

/** The organization whose incident types this client may maintain, or null. */
export const organizerIncidentTypeAdminSession = computed(() =>
  resolveOrganizerAdminSession(CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE),
);

/** The organization whose team designations this client may maintain, or null. */
export const organizerDesignationAdminSession = computed(() =>
  resolveOrganizerAdminSession(CAPABILITY_ORGANIZATION_DESIGNATIONS_MANAGE),
);

/** The organization whose configuration this client may edit, or null. */
export const organizerConfigurationAdminSession = computed(() =>
  resolveOrganizerAdminSession(CAPABILITY_ORGANIZATION_CONFIGURATION_MANAGE),
);
