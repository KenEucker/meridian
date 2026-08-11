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
//
// The two export entries are capability-permitted by five codes at once and
// additionally by how wide the role carrying them reaches, so they read the same
// authority their surfaces render from rather than restating the rule here. See
// `reporting/reportingExports`.

import { computed, type ComputedRef } from "vue";

import { directoryMenuPresent } from "@/directory/directoryModel";
import { useEventHorizonMenuPresence } from "@/event-horizon/eventHorizonModel";
import {
  departmentReportingExportAuthority,
  organizerReportingExportAuthority,
} from "@/reporting/reportingExports";
import {
  CAPABILITY_DEPARTMENT_ADMINISTER,
  CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE,
  CAPABILITY_DEPARTMENT_BRANDING_MANAGE,
  CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN,
  CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE,
  CAPABILITY_DEPARTMENT_PRESENCE_MANAGE,
  CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE,
  CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW,
  CAPABILITY_EVENT_CREDENTIALS_REVOKE,
  CAPABILITY_INCIDENTS_VIEW,
  CAPABILITY_ORGANIZATION_BRANDING_MANAGE,
  CAPABILITY_ORGANIZATION_APPLICATIONS_REVIEW,
  CAPABILITY_ORGANIZATION_AUDIT_REVIEW,
  CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE,
  CAPABILITY_ORGANIZATION_DESIGNATIONS_MANAGE,
  CAPABILITY_ORGANIZATION_EVENTS_MANAGE,
  CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE,
  CAPABILITY_ORGANIZATION_STAFF_MANAGE,
  CAPABILITY_POLICIES_VIEW_PUBLISHED,
  CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
  CAPABILITY_REPORTS_CREDITS_EARNED_EXPORT,
  CAPABILITY_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW,
  ROLE_DEPARTMENT_LEAD,
  ROLE_LEAD_ORGANIZER,
  ROLE_ORGANIZER,
  ROLE_SHIFT_LEAD,
} from "@/session/permissionCodes";
import {
  departmentHasCapability,
  departmentHasRole,
  selectedSessionDepartment,
  selectedSessionDepartmentRouteParams,
  sessionDepartmentAccesses,
  sessionEstablished,
  sessionEventContext,
  sessionLedTeams,
  type SessionDepartmentAccess,
} from "@/session/sessionAccess";
import {
  sessionOrganizationId,
  sessionSwitchingAvailable,
} from "@/session/sessionContext";
import { visibleLinks } from "@/session/hiddenPages";
import { menuLinks } from "@/session/menuPages";

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
 *
 * Calibrated one item above the fullest role a session carries — a department
 * lead holding every department capability — so the person with the most to
 * reach still reads one list. It moved from ten to eleven when the shift board
 * joined the personal pages (M18.2), from eleven to twelve when acknowledgments
 * did (M18.6), from twelve to thirteen when the document library did (M18.7),
 * and from thirteen to fifteen when the two dashboards arrived (M18.28) — one
 * personal and one for the department — each time for the same reason: that lead
 * gained a page rather than gaining a reason to hunt through two menus.
 */
export const COMBINED_NAVIGATION_MAX_ITEMS = 15;

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
      // one workflow that survives having no department route to build — and
      // the Directory survives with it, because it is organization-scoped and
      // belongs to anybody (DIR-002).
      return visibleLinks([...incidentLinks(department), ...directoryLinks()]);
    }

    const isDepartmentLead = departmentHasRole(department, ROLE_DEPARTMENT_LEAD);

    /*
     * The department dashboard (M18.28; UI contract 12.4, 13.2, 13.3).
     *
     * First, because it is the department's home rather than one of the
     * workflows inside it. Offered on any department standing that opens a
     * widget group — the lead role for 13.2, and the operational capabilities
     * for 13.3 — so somebody who would be shown an empty page is not sent to it
     * (CLIENT-005).
     */
    if (
      isDepartmentLead ||
      departmentHasCapability(
        department,
        CAPABILITY_DEPARTMENT_PRESENCE_MANAGE,
        CAPABILITY_DEPARTMENT_ATTENDANCE_MANAGE,
        CAPABILITY_DEPARTMENT_EQUIPMENT_MANAGE,
        CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN,
      )
    ) {
      links.push({
        label: "Dashboard",
        description: "Coverage, check-in, trainings, and the shift running now.",
        to: { name: "events.departments.show", params },
      });
    }

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

    links.push(...directoryLinks());

    /*
     * The reader's own preference, applied last (M18.69).
     *
     * Last on purpose: everything above decides what this person is permitted
     * to reach, and this decides what they want to look at. Filtering earlier
     * would tangle the two and make a display preference read like an
     * authority decision — and it is the capability checks, not this, that
     * CLIENT-005 is about.
     */
    return visibleLinks(links);
  });
}

/**
 * The Directory (M18.75; DIR-002, DIR-005; UI contract 19D.2).
 *
 * Association-permitted, like Me: the chart belongs to anybody with a session,
 * and what a viewer sees inside it is the node's visibility answer rather than
 * a reason to gate the entry. The one gate is the organization's own setting —
 * present only where the node has confirmed the organization offers a
 * Directory, and absent otherwise with no entry explaining why (DIR-005).
 */
function directoryLinks(): WorkflowLink[] {
  if (!directoryMenuPresent()) {
    return [];
  }

  return [
    {
      label: "Directory",
      description:
        "The organization chart: departments, teams, and who is where.",
      to: { name: "directory" },
    },
  ];
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
    /*
     * The IMS attention dashboard (M18.28; UI contract 12.7, 13.5). Listed
     * beside Field Reports rather than in the tab bar for the same reason: the
     * Incidents workspace is the hub, and both of these are pages reached from
     * inside it.
     */
    {
      label: "IC Dashboard",
      pageLabel: "Incident Command dashboard",
      description: "Open, serious, on-scene, and monitoring incidents at a glance.",
      to: { name: "ims.dashboard" },
    },
    {
      label: "Field Reports",
      description: "Event Field Reports visible to Incident Command.",
      to: { name: "ims.field-reports.index" },
    },
  ];
}

/**
 * Personal pages reached from the home directory rather than from the menu.
 *
 * These are still the reader's own pages and still belong on Home — Home is the
 * full map of what somebody can reach. They are kept out of the menu on the
 * same reasoning that keeps IMS Field Reports out of the tab bar: the menu
 * names the places a person works out of, and neither of these is one. Reading
 * a policy and checking what you have already accepted are things somebody does
 * once, from a link or from Home, not a hub they sit in for a stretch of the
 * event — and a menu that lists them is a menu with two more entries to scan
 * every time somebody is looking for the one they use hourly.
 * {@see useNavigationSections} keeps them in the "You" section.
 */
function staffDirectoryLinks(): WorkflowLink[] {
  return [
    /*
     * Acknowledgments (M18.6; POL-023, POL-043). Personal, like Me and My
     * Field Reports, and gated by no capability: being asked to read a
     * document is a fact about a person rather than a permission somebody
     * grants them. The page says plainly when nobody has asked them anything,
     * which is the honest empty state — a missing entry would leave a staff
     * member with no way to check what they accepted.
     */
    {
      label: "Acknowledgments",
      description: "Documents you were asked to read, and the version you accepted.",
      to: { name: "staff.documents.acknowledgments" },
    },
    /*
     * The document library (M18.7; POL-006, POL-008 through POL-012). This is
     * where a member's Documents entry now leads, and it is here for everybody
     * rather than only for members without an Admin workflow: a policy is
     * published to a person, so reading one is personal work even for the lead
     * who maintains a different one. The department library it replaced could
     * only show one department's documents at a time, from inside the surface
     * for maintaining them; leads still reach that library from Admin, where
     * authoring lives.
     */
    {
      label: "Documents",
      pageLabel: "Policies & Procedures",
      description: "Policies and procedures published to you.",
      to: { name: "staff.documents.index" },
    },
  ];
}

/**
 * The Staff menu: the pages that belong to the person rather than to a
 * workflow.
 *
 * Me is always here for a signed-in user, and Event Info and the shift board sit
 * next to it whenever the session resolved an event. My Field Reports belongs
 * here too: authoring a Field Report is something a person does, not something a
 * department workflow owns, and every role can do it. Leads stop there, because
 * their Trainings page is reached from inside the Planning workflow they already
 * work out of; members get it here, since they have no workflow to reach it
 * from.
 *
 * Acknowledgments and the document library are not here. They are personal
 * pages and they are on Home, but they are not places somebody works out of —
 * see {@see staffDirectoryLinks}.
 */
export function useStaffLinks(): ComputedRef<WorkflowLink[]> {
  const eventContext = useEventContext();
  const eventHorizonPresent = useEventHorizonMenuPresence(
    computed(() => eventContext.value?.eventId ?? null),
  );

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

    /*
     * The Event Horizon (M18.43; HORIZON-010 through HORIZON-013; UI contract
     * 19C.2). First among the event-scoped entries, because inside its window
     * it is the staff landing destination: what you still have outstanding is
     * the first question of the lead-up. Present only while the node's last
     * answer said the window applies and the member has not hidden it —
     * absent otherwise, with no entry explaining that it would have been here.
     */
    if (eventContext.value && eventHorizonPresent.value) {
      links.push({
        label: "Horizon",
        pageLabel: "Event Horizon",
        description: "What you still have outstanding before this event.",
        to: { name: "staff.event-horizon" },
      });
    }

    if (eventContext.value) {
      links.push(
        {
          label: "Event Info",
          description: "Directions, arrival, packing, food, and housing.",
          to: {
            name: "events.info",
            params: { eventId: eventContext.value.eventId },
          },
        },
        /*
         * The staff dashboard (M18.28; UI contract 12.3, 13.1). Personal like Me
         * and gated by no capability, because every widget on it is about the
         * reader's own record — their shift, their departments, their
         * outstanding documents. It needs an event, since all of them are
         * event-scoped, which is why it sits inside this branch.
         *
         * Third rather than second: Event Info's adjacency to Me is an
         * invariant of its own, and a dashboard that pushed the arrival
         * information down the list would be trading a page somebody reads once
         * before they travel for one they read during the event.
         */
        {
          label: "Dashboard",
          description: "Your current shift, what is next, and what needs you.",
          to: { name: "staff.dashboard" },
        },
        /*
         * The shift board (M18.2; SHIFT-018). A personal page rather than a
         * department one, and event-scoped rather than department-scoped:
         * somebody who works two departments at an event signs up across both
         * from one screen, and which departments are on it is the node's answer
         * from their own memberships.
         *
         * This is where a member's Shifts entry now leads. The department shift
         * list it replaced showed the same schedule and could do nothing with
         * it; leads still reach that list from the Admin workflow, where
         * creating and editing shifts lives.
         */
        {
          label: "Shifts",
          pageLabel: "Shift Board",
          description: "Shifts you can take, and the ones you are already on.",
          to: { name: "staff.shifts.index" },
        },
      );
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
      return visibleLinks(links);
    }

    links.push({
      label: "Trainings",
      description: "Department training schedule, signup, and completion.",
      to: { name: "events.departments.trainings.index", params },
    });

    // The reader's own preference, applied after the checks that built the
    // list. See the note at the end of `useWorkflowLinks`.
    return visibleLinks(links);
  });
}

/*
 * The two menus as the shell actually draws them (M18.69).
 *
 * Everything above answers "what may this person reach and what have they kept"
 * — one list, and the one Home renders from. These two answer the narrower
 * question the menus ask: of those pages, which does this reader work out of.
 *
 * The filter belongs here rather than inside the builders because it is the
 * only place it is true. A page taken out of a menu is still on Home, still in
 * the command palette, and still reachable; folding it into `useStaffLinks` or
 * `useWorkflowLinks` would take it off the map as well, which is the other
 * preference and not this one.
 */

/**
 * Every page this reader could put in a menu, whether or not it is in one.
 *
 * The menu entries their session builds, plus the four Home-only pages that may
 * be promoted into one. This is what Settings renders a row from, so the two
 * halves have to arrive together: a reader deciding what their menu holds is
 * choosing among all of it at once, and a control that offered only what is
 * already there could never add anything.
 *
 * Filtered by the hidden-pages preference, because the builders below already
 * are. A page somebody has put away has no menu question left to answer.
 */
export function useMenuCandidateLinks(): ComputedRef<WorkflowLink[]> {
  const staff = useStaffLinks();
  const workflows = useWorkflowLinks();

  return computed(() => {
    if (!sessionEstablished.value) {
      return [];
    }

    return [
      ...staff.value,
      ...visibleLinks(staffDirectoryLinks()),
      ...workflows.value,
      ...visibleLinks(imsDirectoryLinks(selectedSessionDepartment.value)),
    ];
  });
}

/**
 * The Workflows menu: the workflow hubs this reader kept in it, plus any
 * Incident Command page they asked for.
 *
 * The promoted pages are appended rather than woven in. They are additions to a
 * menu whose order says something — the department's home first, then the
 * workflows inside it — and a reader who adds a page is adding it to the end of
 * the list they already read.
 */
export function useWorkflowMenuLinks(): ComputedRef<WorkflowLink[]> {
  const links = useWorkflowLinks();

  return computed(() =>
    menuLinks([
      ...links.value,
      ...visibleLinks(imsDirectoryLinks(selectedSessionDepartment.value)),
    ]),
  );
}

/**
 * The Staff menu: the personal pages this reader kept in it, plus
 * Acknowledgments or the document library if they asked for either.
 */
export function useStaffMenuLinks(): ComputedRef<WorkflowLink[]> {
  const links = useStaffLinks();

  return computed(() => {
    if (links.value.length === 0) {
      return [];
    }

    return menuLinks([...links.value, ...visibleLinks(staffDirectoryLinks())]);
  });
}

/**
 * Whether the app shell renders Staff and Workflows as one menu or two.
 *
 * Under the threshold the shell shows a single list, because splitting a short
 * list across two dropdowns makes the reader guess which one holds the page.
 *
 * Counted after the reader's own trimming, which is the count that matters:
 * the threshold is about how long a list somebody has to read, and a reader who
 * has taken five entries out of their menus is reading the shorter one.
 */
export function useCombinedNavigation(): ComputedRef<{
  readonly combined: boolean;
  readonly links: readonly WorkflowLink[];
}> {
  const workflowLinks = useWorkflowMenuLinks();
  const staffLinks = useStaffMenuLinks();

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
  const staffLinks = useStaffMenuLinks();

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
        // The menu's personal pages plus the two that are only listed here.
        links: [...staffLinks.value, ...staffDirectoryLinks()],
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
    const contextLinks: WorkflowLink[] = [];

    if (sessionSwitchingAvailable.value) {
      contextLinks.push({
        label: "Organization",
        pageLabel: "Switch organization",
        description: "The organizations you hold an association with.",
        to: { name: "organizations.index" },
      });

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
    }

    /*
     * The third context screen (M18.29; UI contract 12.2
     * `context.departments`).
     *
     * Offered on different terms from the two above, because it answers a
     * different question. Switching organization or event is connected-only —
     * only the node can resolve a session somewhere else — while entering a
     * department reads what the session already carries, so this entry survives
     * a locked node and an offline client. It needs an event, because a
     * department space is an address inside one, and it needs a department to
     * enter.
     */
    if (params !== null && sessionDepartmentAccesses.value.length > 0) {
      contextLinks.push({
        label: "Departments",
        pageLabel: "Department spaces",
        description: "The departments you are associated with in this event.",
        to: {
          name: "events.departments.index",
          params: { eventId: params.eventId },
        },
      });
    }

    if (contextLinks.length > 0) {
      sections.push({
        title: "Context",
        description:
          "The organization, event, and department this device is working in.",
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

    // Read from the section rather than from the menu list, so a page listed
    // only on Home still counts as already listed.
    const staffRouteNames = new Set(
      sections[0].links.map((link) => link.to.name),
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

      /*
       * The department roster (M18.30; UI contract 12.4; VOL-012).
       *
       * Three standings open it and they open different amounts of it, which
       * is why the entry answers to three checks rather than one: department
       * administration and planning read the whole department, and a team lead
       * reads the teams they lead. Whether the rows carry emergency contacts is
       * not decided here at all — the node decides it on the read, and a client
       * that guessed would be guessing about a next-of-kin phone number.
       *
       * The Admin page's staff list is not this. That one exists to put people
       * on teams and carries no way to reach anybody.
       */
      if (
        departmentHasCapability(
          department,
          CAPABILITY_DEPARTMENT_ADMINISTER,
          CAPABILITY_DEPARTMENT_SCHEDULE_MANAGE,
        ) ||
        departmentHasRole(department, ROLE_SHIFT_LEAD)
      ) {
        departmentPages.push({
          label: "Roster",
          description:
            "The department's staff list, their teams, and how to reach them.",
          to: { name: "events.departments.roster", params },
        });
      }

      /*
       * The department export surface (M18.26; REPORT-014, REPORT-007).
       *
       * Offered on the department-scoped half of the export authority, so a
       * lead reaches the page that exports the department they are working in
       * and an organizer does not reach it from their Organizers Department at
       * all — their entry is Exports under Organization pages, which covers
       * the whole event.
       */
      if (departmentReportingExportAuthority.value !== null) {
        departmentPages.push({
          label: "Exports",
          description:
            "Roster, contact, hours, and credit files for this department.",
          to: { name: "events.departments.exports.index", params },
        });
      }

      /*
       * Waiver administration for department and team leads (M18.18;
       * WAIVER-010). Role-permitted rather than capability-permitted, the way
       * Team Overview is: waiver authority follows the waiver's scope, which
       * department leads and team leads hold as roles. The surface itself is
       * shared with organizers, and the node answers each caller with the
       * scopes they actually maintain.
       */
      if (
        departmentHasRole(department, ROLE_DEPARTMENT_LEAD) ||
        departmentHasRole(department, ROLE_SHIFT_LEAD)
      ) {
        departmentPages.push({
          label: "Waivers",
          description:
            "Waivers for your department and teams, and completion recording.",
          to: { name: "organizer.waivers.index" },
        });
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

      /*
       * Deployment options (M18.30; SLB-009; UI contract 12.4).
       *
       * Beside Equipment, because the two are the same kind of work: what a
       * department has, and where it puts people. Offered on either the
       * capability that assigns staff to a deployment or the one that
       * administers the department, which is the contract's "department
       * operations/administration as permitted" — the operator moving people
       * at two in the morning is the one who finds out the list is missing a
       * gate, and sending them to find a lead is how it stays missing.
       */
      if (
        departmentHasCapability(
          department,
          CAPABILITY_DEPARTMENT_DEPLOYMENTS_ASSIGN,
          CAPABILITY_DEPARTMENT_ADMINISTER,
        )
      ) {
        departmentPages.push({
          label: "Deployments",
          description:
            "The places this department deploys to, and what is standing at each.",
          to: { name: "events.departments.deployments.index", params },
        });
      }

      /*
       * Credit review (M18.30; CREDIT-005; UI contract 12.4).
       *
       * Gated on the credits export capability, which is the same authority the
       * read behind the page answers to: looking at the ledger and downloading
       * it are the same rows and the same disclosure. Separate from Exports
       * next to it because that page offers five files and this one answers a
       * question — what did this department earn, and how was each number
       * arrived at.
       */
      if (
        departmentHasCapability(
          department,
          CAPABILITY_REPORTS_CREDITS_EARNED_EXPORT,
        )
      ) {
        departmentPages.push({
          label: "Credits",
          description:
            "What this department earned at this event, and the basis behind every number.",
          to: { name: "events.departments.credits.index", params },
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

    /*
     * The organizer dashboard (M18.28; UI contract 12.6, 13.4).
     *
     * Role-permitted rather than capability-permitted, like Department Overview
     * and Team Overview: the contract grants the whole 13.4 group to "organizer"
     * rather than to any code in the catalogue, and the node opens it on the
     * same standing. First in the section, because it is the organization's home
     * and the rest of the section is what it links into.
     */
    if (
      departmentHasRole(department, ROLE_ORGANIZER) ||
      departmentHasRole(department, ROLE_LEAD_ORGANIZER)
    ) {
      organizationPages.push({
        label: "Dashboard",
        pageLabel: "Organizer dashboard",
        description: "Event readiness, coverage, applications, and the operations window.",
        to: { name: "organizer.dashboard" },
      });
    }

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
     * Event administration (M18.29; UI contract 12.6 `organizer.events`;
     * ORG-006). Beside Departments, because the two are the same kind of work —
     * the shape of the organization, and the occasions it produces — and the
     * Incident Command designation is made on one from a list the other
     * maintains.
     */
    if (departmentHasCapability(department, CAPABILITY_ORGANIZATION_EVENTS_MANAGE)) {
      organizationPages.push({
        label: "Events",
        description:
          "Event identity, published dates, the active event window, and Incident Command.",
        to: { name: "organizer.events.index" },
      });
    }

    /*
     * The credentials surface (M16.22, M18.5; REPORT-001; CRED-011). Two
     * capabilities reach it and either one is enough, because the page carries
     * a featureset for each: a department lead holds the export and exports
     * their own department (REPORT-007), an Incident Command lead holds
     * revocation and no export at all, and an organizer holds both. Gating on
     * one of them would have hidden the page from half of who it is for.
     */
    if (
      departmentHasCapability(
        department,
        CAPABILITY_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
      ) ||
      departmentHasCapability(department, CAPABILITY_EVENT_CREDENTIALS_REVOKE)
    ) {
      organizationPages.push({
        label: "Credentials",
        description: departmentHasCapability(
          department,
          CAPABILITY_EVENT_CREDENTIALS_REVOKE,
        )
          ? "Event credential administration and eligibility export."
          : "Event credential eligibility export.",
        to: { name: "organizer.credentials.index" },
      });
    }

    /*
     * The organizer export surface (M18.26; REPORT-014, REPORT-006).
     *
     * Beside Credentials rather than inside it: that page offers the one export
     * that reads the records it is a page for, and this one offers all five
     * Alpha 1 exports across the event. An organizer holding four of the five
     * codes still gets this entry and sees exactly those four (CLIENT-005).
     */
    if (organizerReportingExportAuthority.value !== null) {
      organizationPages.push({
        label: "Exports",
        description:
          "Event-wide credential, roster, contact, hours, and credit files.",
        to: { name: "organizer.exports.index" },
      });
    }

    // The organization configuration surface ORG-018 requires. One entry rather
    // than one per setting: the page is the hub those settings live on, and
    // each featureset inside it carries its own capability, so any one of them
    // is a reason to offer the door.
    if (
      departmentHasCapability(
        department,
        CAPABILITY_ORGANIZATION_INCIDENT_TYPES_MANAGE,
        CAPABILITY_ORGANIZATION_DESIGNATIONS_MANAGE,
      )
    ) {
      organizationPages.push({
        label: "Configuration",
        description:
          "Organization settings, including incident types and team designations.",
        to: { name: "organizer.configuration.index" },
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

    /*
     * Acknowledgment requirements and review (M18.6; POL-023, POL-046,
     * POL-047). Next to Documents and a separate entry, because it answers to a
     * separate capability: publishing a document and deciding it must be
     * acknowledged are different decisions, and the second is the one this page
     * is for.
     */
    if (
      departmentHasCapability(
        department,
        CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW,
      )
    ) {
      organizationPages.push({
        label: "Acknowledgments",
        description:
          "What must be acknowledged at signup and training, and who has.",
        to: { name: "organizer.document-acknowledgments.index" },
      });
    }

    /*
     * Handle and profile picture change request review (M18.20D; VOL-019).
     * Its own capability rather than the staff one beside it: the Staff
     * Coordinator decides these and administers no staff record, so gating this
     * on `organization.staff.manage` would have hidden the page from half of
     * who it is for.
     */
    if (
      departmentHasCapability(
        department,
        CAPABILITY_STAFF_PROFILE_CHANGE_REQUESTS_REVIEW,
      )
    ) {
      organizationPages.push({
        label: "Profile requests",
        description:
          "Staff handle changes and profile pictures waiting on a decision.",
        to: { name: "organizer.profile-change-requests.index" },
      });
    }

    /*
     * Application review (M18.21A; APP-005, APP-019).
     *
     * Capability-permitted here, and only here. The department-lead read-only
     * visibility APP-011 grants is standing rather than a capability — it
     * depends on which applications named which department — so it is the
     * node's answer on the read, not a navigation entry this module can derive.
     * A lead reaches the page through a link or an address; the entry is for
     * the people whose job it is.
     */
    if (
      departmentHasCapability(
        department,
        CAPABILITY_ORGANIZATION_APPLICATIONS_REVIEW,
      )
    ) {
      organizationPages.push({
        label: "Applications",
        description:
          "People offering to join or to staff an event, and the link that invites them.",
        to: { name: "organizer.applications.index" },
      });
    }

    /*
     * Waiver administration (M18.18; WAIVER-010). Waiver authority follows
     * the waiver's scope — no single capability carries it — so this entry is
     * a nav approximation gated on the organizer-held departments capability;
     * the node resolves each caller's real maintainable scopes when the page
     * loads, and department and team leads reach the same surface from their
     * department pages below (CLIENT-006).
     */
    if (
      departmentHasCapability(department, CAPABILITY_ORGANIZATION_DEPARTMENTS_MANAGE)
    ) {
      organizationPages.push({
        label: "Waivers",
        description:
          "Waivers, their scope and expiration, and completion recording.",
        to: { name: "organizer.waivers.index" },
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

    /*
     * Audit review (M18.29; requirements 2.4; UI contract 12.6). Last in the
     * section, because it is the page somebody opens about work done on the
     * others rather than a place work is done.
     */
    if (departmentHasCapability(department, CAPABILITY_ORGANIZATION_AUDIT_REVIEW)) {
      organizationPages.push({
        label: "Audit",
        description: "Who changed what in this organization, when, and why.",
        to: { name: "organizer.audit.index" },
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

    /*
     * The reader's own preference, applied to the whole directory at once
     * (M18.69).
     *
     * Home is the full map of what somebody can reach, so hiding a page has to
     * take it off the map as well as out of the menus — a preference that only
     * cleaned up the tab bar would leave the tile it was meant to remove sitting
     * on the first screen the reader sees.
     *
     * A section left with nothing in it goes too. A heading over an empty grid
     * describes a group the reader has emptied on purpose, and the honest
     * rendering of that is nothing at all.
     */
    return sections
      .map((section) => ({ ...section, links: visibleLinks(section.links) }))
      .filter((section) => section.links.length > 0);
  });
}
