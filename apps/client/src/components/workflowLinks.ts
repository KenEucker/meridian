// Navigation, derived from the capability codes the session response carries
// (M16.6; CLIENT-004, CLIENT-005; UI operating guide 18.1; UI contract 19A.1).
//
// Every entry below names the code that permits it. Nothing is built from
// fixture data, build configuration, or a hardcoded list of who sees what, and
// no entry renders for a user holding no permitting code — which is the whole of
// CLIENT-005 on the client side. It is still presentation: the server refuses
// the request regardless of what was rendered (CLIENT-006), and a rendered link
// is not evidence of authority.
//
// Three kinds of entry appear here and they are permitted by different things,
// so it is worth being explicit about which is which:
//
//  - **Capability-permitted.** The ordinary case. Logistics needs
//    `department.attendance.manage`, Admin needs `department.administer`, and so
//    on, read from `PermissionCatalog` through the session response.
//  - **Role-permitted.** Department Overview and Team Overview, for which the
//    catalog registers no capability today. Both use the effective role code the
//    same response carries; see `permissionCodes` for why.
//  - **Association-permitted.** Me, the author's own Field Reports, and the
//    member's Documents/Shifts/Trainings pages. These are not permission-scoped
//    surfaces — they are the user's own, or their department's published pages —
//    so what permits them is holding a session and belonging to a team, both of
//    which come from the session response too.

import { computed, type ComputedRef } from "vue";

import {
  CAPABILITY_DEPARTMENT_ADMINISTER,
  CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE,
  CAPABILITY_DEPARTMENT_BRANDING_MANAGE,
  CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN,
  CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE,
  CAPABILITY_DEPARTMENT_PRESENCE_MANAGE,
  CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE,
  CAPABILITY_INCIDENTS_VIEW,
  CAPABILITY_ORGANIZATION_BRANDING_MANAGE,
  CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE,
  CAPABILITY_ORGANIZATION_STAFF_MANAGE,
  CAPABILITY_POLICIES_VIEW_PUBLISHED,
  CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
  ROLE_DEPARTMENT_LEAD,
  ROLE_SHIFT_LEAD,
} from "@/session/permissionCodes";
import {
  departmentHasCapability,
  departmentHasRole,
  selectedSessionDepartment,
  selectedSessionDepartmentRouteParams,
  sessionEstablished,
  sessionEventContext,
  sessionLedTeams,
  type SessionDepartmentAccess,
} from "@/session/sessionAccess";
import {
  sessionOrganizationId,
  sessionSwitchingAvailable,
} from "@/session/sessionContext";

export type WorkflowLink = {
  /** Short label for the workflow tab bar, where horizontal space is tight. */
  readonly label: string;
  /** Full name for the home screen, when the tab label is abbreviated. */
  readonly pageLabel?: string;
  readonly description?: string;
  readonly to: {
    readonly name: string;
    readonly params?: Record<string, string>;
  };
};

export type NavigationSection = {
  readonly title: string;
  readonly description: string;
  readonly links: readonly WorkflowLink[];
};

/**
 * Below this many total nav items, two dropdowns cost more than they organize:
 * the reader has to guess which menu holds the page instead of reading one
 * short list. At or above it, the split earns its keep.
 */
export const COMBINED_NAVIGATION_MAX_ITEMS = 10;

/**
 * The event the interface is currently working in, or null when the session
 * resolved no single event.
 *
 * Event Info and the other event-scoped staff pages are only constructible with
 * an event id, so this is the gate for showing them at all.
 */
export function useEventContext(): ComputedRef<{
  readonly eventId: string;
  readonly eventLabel: string | null;
} | null> {
  return computed(() => sessionEventContext.value);
}

/**
 * Top-level workflows.
 *
 * A workflow is a hub someone works out of for a stretch of the event, not
 * every page they can reach. Pages that belong to a workflow are reached from
 * inside it: Shifts and Documents live under Admin, Trainings lives under
 * Planning. Personal pages live in the Staff menu instead.
 */
export function useWorkflowLinks(): ComputedRef<WorkflowLink[]> {
  return computed(() => {
    const department = selectedSessionDepartment.value;
    const params = selectedSessionDepartmentRouteParams.value;
    const links: WorkflowLink[] = [];

    if (params === null) {
      // Incidents is event-scoped rather than department-scoped, so it is the
      // one workflow that survives having no department route to build.
      return incidentLinks(department);
    }

    const isDepartmentLead = departmentHasRole(department, ROLE_DEPARTMENT_LEAD);

    if (isDepartmentLead) {
      links.push({
        label: "Overview",
        description: "Shift health, exceptions, and current staffing.",
        to: { name: "events.departments.overview", params },
      });
    }

    // Team leads without department lead authority get the team-scoped
    // equivalent rather than a narrowed copy of Department Overview (M11.20).
    const ledTeam = isDepartmentLead
      ? undefined
      : sessionLedTeams(department, ROLE_SHIFT_LEAD)[0];

    if (ledTeam) {
      links.push({
        label: "Team",
        pageLabel: "Team Overview",
        description: "Your team's shifts, roster, and current staffing.",
        to: {
          name: "events.departments.teams.show",
          params: { ...params, teamId: ledTeam.teamId },
        },
      });
    }

    if (departmentHasCapability(department, CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE)) {
      links.push({
        label: "Planning",
        description: "Coverage across teams and time, plus shifts and trainings.",
        to: { name: "events.departments.planning", params },
      });
    }

    // The Logistics desk is roster, attendance, and equipment handoff — the
    // three capabilities the department logistics role carries — so any one of
    // them is something to do there.
    if (
      departmentHasCapability(
        department,
        CAPABILITY_DEPARTMENT_PRESENCE_MANAGE,
        CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE,
        CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE,
      )
    ) {
      links.push({
        label: "Logistics",
        description: "Roster, attendance, and equipment handoff.",
        to: { name: "events.departments.logistics", params },
      });
    }

    if (
      departmentHasCapability(department, CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN)
    ) {
      links.push({
        label: "Operations",
        description:
          "Operations Center deployments and capability-based shortcuts.",
        to: { name: "events.departments.operations", params },
      });
    }

    links.push(...incidentLinks(department));

    if (departmentHasCapability(department, CAPABILITY_DEPARTMENT_ADMINISTER)) {
      links.push({
        label: "Admin",
        description: "Department details, teams and staff, and documents.",
        to: { name: "events.departments.teams.index", params },
      });
    }

    return links;
  });
}

/**
 * The Incidents workspace.
 *
 * IMS Field Reports is deliberately absent from the workflow tab bar. It is a
 * page IC roles reach from inside the Incidents workspace they already work out
 * of, not a hub they sit in for a stretch of the event, so it is listed in the
 * home directory and linked from Incidents instead.
 * {@see imsDirectoryLinks} keeps it in the home directory.
 */
function incidentLinks(
  department: SessionDepartmentAccess | null,
): WorkflowLink[] {
  if (!departmentHasCapability(department, CAPABILITY_INCIDENTS_VIEW)) {
    return [];
  }

  return [
    {
      label: "Incidents",
      description: "Restricted incident workspace for IC roles.",
      to: { name: "ims.incidents.index" },
    },
  ];
}

/**
 * Workflow pages reached from inside the Incidents workspace rather than from
 * the tab bar.
 *
 * These are still workflow pages and still belong in the home directory — the
 * home screen is the full map of what someone can reach. They are kept out of
 * the tab bar because the tab bar names hubs, and a reader scanning eight tabs
 * should not have to tell "Incidents" and "Reports" apart mid-event.
 */
function imsDirectoryLinks(
  department: SessionDepartmentAccess | null,
): WorkflowLink[] {
  if (!departmentHasCapability(department, CAPABILITY_INCIDENTS_VIEW)) {
    return [];
  }

  return [
    {
      label: "Field Reports",
      description: "Event Field Reports visible to Incident Command.",
      to: { name: "ims.field-reports.index" },
    },
  ];
}

/**
 * The Staff menu: the pages that belong to the person rather than to a
 * workflow.
 *
 * Me is always here for a signed-in user, and Event Info sits next to it
 * whenever the session resolved an event. My Field Reports belongs here too:
 * authoring a Field Report is something a person does, not something a
 * department workflow owns, and every role can do it. Leads stop there, because
 * their Documents/Shifts/Trainings pages are reached from inside the Admin and
 * Planning workflows they already work out of; members get those pages here,
 * since they have no workflow to reach them from.
 */
export function useStaffLinks(): ComputedRef<WorkflowLink[]> {
  const eventContext = useEventContext();

  return computed(() => {
    if (!sessionEstablished.value) {
      return [];
    }

    const department = selectedSessionDepartment.value;
    const params = selectedSessionDepartmentRouteParams.value;
    const links: WorkflowLink[] = [
      {
        label: "Me",
        description: "Your shifts, trainings, and event information.",
        to: { name: "staff.me" },
      },
    ];

    if (eventContext.value) {
      links.push({
        label: "Event Info",
        description: "Directions, arrival, packing, food, and housing.",
        to: {
          name: "events.info",
          params: { eventId: eventContext.value.eventId },
        },
      });
    }

    links.push({
      label: "My Field Reports",
      description: "Field report author workspace.",
      to: { name: "staff.field-reports.index" },
    });

    if (
      params === null ||
      department === null ||
      department.teams.length === 0 ||
      departmentHasCapability(department, CAPABILITY_DEPARTMENT_ADMINISTER)
    ) {
      return links;
    }

    links.push(
      {
        label: "Documents",
        description: "Policies and procedures published to your department.",
        to: { name: "events.departments.documents.index", params },
      },
      {
        label: "Shifts",
        description: "Shifts your teams are eligible for.",
        to: { name: "events.departments.shifts.index", params },
      },
      {
        label: "Trainings",
        description: "Department training schedule, signup, and completion.",
        to: { name: "events.departments.trainings.index", params },
      },
    );

    return links;
  });
}

/**
 * Whether the app shell renders Staff and Workflows as one menu or two.
 *
 * Under the threshold the shell shows a single list, because splitting a short
 * list across two dropdowns makes the reader guess which one holds the page.
 */
export function useCombinedNavigation(): ComputedRef<{
  readonly combined: boolean;
  readonly links: readonly WorkflowLink[];
}> {
  const workflowLinks = useWorkflowLinks();
  const staffLinks = useStaffLinks();

  return computed(() => {
    const links = [...staffLinks.value, ...workflowLinks.value];

    return {
      combined: links.length < COMBINED_NAVIGATION_MAX_ITEMS,
      links,
    };
  });
}

/**
 * Whether the app shell shows a separate Staff menu. False when the two menus
 * are combined, because the combined menu already carries the staff pages.
 */
export function useShowStaffMenu(): ComputedRef<boolean> {
  const navigation = useCombinedNavigation();
  const staffLinks = useStaffLinks();

  return computed(() => !navigation.value.combined && staffLinks.value.length > 0);
}

/**
 * Every page the signed-in user can reach, grouped for the home screen.
 * Personal pages come first, then the workflows they work out of, then the
 * lead-only department pages those workflows contain.
 *
 * A client with no established session gets nothing at all, rather than a map of
 * pages it would be refused at (CLIENT-005).
 */
export function useNavigationSections(): ComputedRef<NavigationSection[]> {
  const workflowLinks = useWorkflowLinks();
  const staffLinks = useStaffLinks();

  return computed(() => {
    if (!sessionEstablished.value) {
      return [];
    }

    const department = selectedSessionDepartment.value;
    const params = selectedSessionDepartmentRouteParams.value;
    const sections: NavigationSection[] = [
      {
        title: "You",
        description: "Your profile, event information, and personal pages.",
        links: staffLinks.value,
      },
    ];

    /*
     * The context screens (M16.7; CLIENT-012). Contract rule 4.4 puts
     * organization and event switching on Home or a dedicated surface, and this
     * is the Home half of that: the dedicated surfaces are linked from here as
     * well as from the user menu.
     *
     * Absent entirely when switching is unavailable, which is the same rule the
     * shell applies — a locked node, an offline client, and a user with one
     * association each have nothing to switch to, and a Home tile leading to a
     * screen that only explains itself is a tile that wastes a click.
     */
    if (sessionSwitchingAvailable.value) {
      const contextLinks: WorkflowLink[] = [
        {
          label: "Organization",
          pageLabel: "Switch organization",
          description: "The organizations you hold an association with.",
          to: { name: "organizations.index" },
        },
      ];

      if (sessionOrganizationId.value !== null) {
        contextLinks.push({
          label: "Event",
          pageLabel: "Switch event",
          description: "The events you hold an association with here.",
          to: {
            name: "organizations.events.index",
            params: { organizationId: sessionOrganizationId.value },
          },
        });
      }

      sections.push({
        title: "Context",
        description: "The organization and event this device is working in.",
        links: contextLinks,
      });
    }

    const workflowDirectory = [
      ...workflowLinks.value,
      ...imsDirectoryLinks(department),
    ];

    if (workflowDirectory.length > 0) {
      sections.push({
        title: "Workflows",
        description: "Hubs you work out of during the event.",
        links: workflowDirectory,
      });
    }

    const staffRouteNames = new Set(
      staffLinks.value.map((link) => link.to.name),
    );
    const departmentPages: WorkflowLink[] = [];

    if (params !== null) {
      // Shifts, Documents, and Trainings are the department administration
      // pages the Admin workflow contains. Trainings additionally needs
      // `department.trainings.manage`, which both roles holding
      // `department.administer` also hold, so it is not a second check.
      if (departmentHasCapability(department, CAPABILITY_DEPARTMENT_ADMINISTER)) {
        departmentPages.push(
          {
            label: "Shifts",
            description: "Create and maintain department and team shifts.",
            to: { name: "events.departments.shifts.index", params },
          },
          {
            label: "Documents",
            description:
              "Department and team policies, procedures, and fragments.",
            to: { name: "events.departments.documents.index", params },
          },
          {
            label: "Trainings",
            description: "Department training schedule, signup, and completion.",
            to: { name: "events.departments.trainings.index", params },
          },
        );
      }

      // Equipment inventory setup is department logistics/administration work
      // that feeds the Logistics checkout/check-in workflow (M11.18), and the
      // capability that permits it is the one the checkout desk uses.
      if (
        departmentHasCapability(department, CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE)
      ) {
        departmentPages.push({
          label: "Equipment",
          description: "Department equipment inventory and bulk CSV import.",
          to: { name: "events.departments.equipment.index", params },
        });
      }

      // Department branding is narrower than general department admin access:
      // department leads and department administration edit a department
      // branding profile, and a team lead does not (BRAND-019, M15A.7).
      if (
        departmentHasCapability(department, CAPABILITY_DEPARTMENT_BRANDING_MANAGE)
      ) {
        departmentPages.push({
          label: "Branding",
          description: "Department logo, accent color, and surface background.",
          to: { name: "events.departments.branding", params },
        });
      }
    }

    const remainingDepartmentPages = departmentPages.filter(
      (link) => !staffRouteNames.has(link.to.name),
    );

    if (remainingDepartmentPages.length > 0) {
      sections.push({
        title: "Department pages",
        description: "Pages inside your department workflows.",
        links: remainingDepartmentPages,
      });
    }

    const organizationPages: WorkflowLink[] = [];

    if (departmentHasCapability(department, CAPABILITY_ORGANIZATION_STAFF_MANAGE)) {
      organizationPages.push({
        label: "Staff",
        description: "Organizer staff intake and lead selection.",
        to: { name: "organizer.staff.index" },
      });
    }

    if (
      departmentHasCapability(department, CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE)
    ) {
      organizationPages.push({
        label: "Departments",
        description: "Organizer department administration.",
        to: { name: "organizer.departments.index" },
      });
    }

    /*
     * The reporting export entry point (M16.22; REPORT-001). Permitted by the
     * export capability rather than by an organizer role: a department lead
     * holds it too and exports their own department, which is REPORT-007 and is
     * the node's decision to make either way.
     */
    if (
      departmentHasCapability(
        department,
        CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
      )
    ) {
      organizationPages.push({
        label: "Credentials",
        description: "Event credential eligibility export.",
        to: { name: "organizer.credentials.index" },
      });
    }

    if (departmentHasCapability(department, CAPABILITY_POLICIES_VIEW_PUBLISHED)) {
      organizationPages.push({
        label: "Documents",
        description:
          "Organization policies, procedures, fragments, and exports.",
        to: { name: "organizer.documents.index" },
      });
    }

    // Organization branding is organizer / Lead Organizer work
    // (BRAND-019, M15A.6).
    if (
      departmentHasCapability(department, CAPABILITY_ORGANIZATION_BRANDING_MANAGE)
    ) {
      organizationPages.push({
        label: "Branding",
        description:
          "Organization display name, logos, palette, and the department override switch.",
        to: { name: "organizer.branding" },
      });
    }

    if (organizationPages.length > 0) {
      sections.push({
        title: "Organization pages",
        description: "Organizer administration across departments.",
        links: organizationPages,
      });
    }

    // Device diagnostics, which are properties of the hardware rather than
    // permission-scoped surfaces: there is no capability in the catalog for
    // them and the server enforces none. They still require a session, because
    // a client with no user has no shell to show them in.
    sections.push({
      title: "Device",
      description: "Diagnostics for this device and its local server.",
      links: [
        {
          label: "Readiness",
          description: "Device readiness checks.",
          to: { name: "readiness" },
        },
        {
          label: "Health",
          description: "Client and local server diagnostics.",
          to: { name: "settings.about" },
        },
      ],
    });

    return sections;
  });
}
