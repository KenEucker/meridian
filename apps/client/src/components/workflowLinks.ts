import { computed, type ComputedRef } from "vue";

import {
  fixtureDepartmentHasAdminAccess,
  fixtureDepartmentHasOrganizerDepartmentAccess,
  selectedFixtureDepartment,
  selectedFixtureDepartmentRouteParams,
} from "@/department-teams/fixtureDepartmentAccess";

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
 * The event the interface is currently locked to, or null when the context is
 * organization-level and no single event is in scope.
 *
 * Event Info and the other event-scoped staff pages are only constructible with
 * an event id, so this is the gate for showing them at all.
 */
export function useEventContext(): ComputedRef<{
  readonly eventId: string;
  readonly eventLabel: string;
} | null> {
  return computed(() => {
    const department = selectedFixtureDepartment.value;

    return department.eventId
      ? { eventId: department.eventId, eventLabel: department.eventLabel }
      : null;
  });
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
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );

  return computed(() => {
    const department = selectedFixtureDepartment.value;
    const links: WorkflowLink[] = [];

    if (department.isDepartmentLead) {
      links.push({
        label: "Overview",
        description: "Shift health, exceptions, and current staffing.",
        to: {
          name: "events.departments.overview",
          params: departmentRouteParams.value,
        },
      });
    }

    // Team leads without department lead authority get the team-scoped
    // equivalent rather than a narrowed copy of Department Overview (M11.20).
    const ledTeam = department.isDepartmentLead
      ? undefined
      : department.teams.find((team) => team.isTeamLead);

    if (ledTeam) {
      links.push({
        label: "Team",
        pageLabel: "Team Overview",
        description: "Your team's shifts, roster, and current staffing.",
        to: {
          name: "events.departments.teams.show",
          params: {
            ...departmentRouteParams.value,
            teamId: ledTeam.teamId,
          },
        },
      });
    }

    if (department.capabilities.hasPlanning) {
      links.push({
        label: "Planning",
        description: "Coverage across teams and time, plus shifts and trainings.",
        to: {
          name: "events.departments.planning",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasLogistics) {
      links.push({
        label: "Logistics",
        description: "Roster, attendance, and equipment handoff.",
        to: {
          name: "events.departments.logistics",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasOperations) {
      links.push({
        label: "Operations",
        description:
          "Operations Center deployments and capability-based shortcuts.",
        to: {
          name: "events.departments.operations",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasIncidentCommand) {
      links.push({
        label: "Incidents",
        description: "Restricted incident workspace for IC roles.",
        to: { name: "ims.incidents.index" },
      });
      links.push({
        label: "Reports",
        pageLabel: "Field Reports",
        description: "Submitted field reports for review.",
        to: { name: "ims.field-reports.index" },
      });
    }

    if (fixtureDepartmentHasAdminAccess(department)) {
      links.push({
        label: "Admin",
        description: "Department details, teams and staff, and documents.",
        to: {
          name: "events.departments.teams.index",
          params: departmentRouteParams.value,
        },
      });
    }

    return links;
  });
}

/**
 * The Staff menu: the pages that belong to the person rather than to a
 * workflow.
 *
 * Me is always here, and Event Info sits next to it whenever the interface is
 * locked to an event. Leads stop there, because their Documents/Shifts/
 * Trainings pages are reached from inside the Admin and Planning workflows they
 * already work out of; members get those pages here, since they have no
 * workflow to reach them from.
 */
export function useStaffLinks(): ComputedRef<WorkflowLink[]> {
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );
  const eventContext = useEventContext();

  return computed(() => {
    const department = selectedFixtureDepartment.value;
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

    if (
      fixtureDepartmentHasAdminAccess(department) ||
      !department.teams.some((team) => team.isMember)
    ) {
      return links;
    }

    links.push(
      {
        label: "Documents",
        description: "Policies and procedures published to your department.",
        to: {
          name: "events.departments.documents.index",
          params: departmentRouteParams.value,
        },
      },
      {
        label: "Shifts",
        description: "Shifts your teams are eligible for.",
        to: {
          name: "events.departments.shifts.index",
          params: departmentRouteParams.value,
        },
      },
      {
        label: "Trainings",
        description: "Department training schedule, signup, and completion.",
        to: {
          name: "events.departments.trainings.index",
          params: departmentRouteParams.value,
        },
      },
      {
        label: "My Field Reports",
        description: "Field report author workspace.",
        to: { name: "staff.field-reports.index" },
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
 * Every page the current fixture user can reach, grouped for the home screen.
 * Personal pages come first, then the workflows they work out of, then the
 * lead-only department pages those workflows contain.
 */
export function useNavigationSections(): ComputedRef<NavigationSection[]> {
  const workflowLinks = useWorkflowLinks();
  const staffLinks = useStaffLinks();
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );

  return computed(() => {
    const department = selectedFixtureDepartment.value;
    const sections: NavigationSection[] = [
      {
        title: "You",
        description: "Your profile, event information, and personal pages.",
        links: staffLinks.value,
      },
    ];

    if (workflowLinks.value.length > 0) {
      sections.push({
        title: "Workflows",
        description: "Hubs you work out of during the event.",
        links: workflowLinks.value,
      });
    }

    const staffRouteNames = new Set(
      staffLinks.value.map((link) => link.to.name),
    );
    const departmentPages: WorkflowLink[] = [];

    if (fixtureDepartmentHasAdminAccess(department)) {
      departmentPages.push(
        {
          label: "Shifts",
          description: "Create and maintain department and team shifts.",
          to: {
            name: "events.departments.shifts.index",
            params: departmentRouteParams.value,
          },
        },
        {
          label: "Documents",
          description:
            "Department and team policies, procedures, and fragments.",
          to: {
            name: "events.departments.documents.index",
            params: departmentRouteParams.value,
          },
        },
        {
          label: "Trainings",
          description: "Department training schedule, signup, and completion.",
          to: {
            name: "events.departments.trainings.index",
            params: departmentRouteParams.value,
          },
        },
      );
    }

    // Equipment inventory setup is department logistics/administration work
    // that feeds the Logistics checkout/check-in workflow (M11.18).
    if (department.isDepartmentLead || department.capabilities.hasLogistics) {
      departmentPages.push({
        label: "Equipment",
        description: "Department equipment inventory and bulk CSV import.",
        to: {
          name: "events.departments.equipment.index",
          params: departmentRouteParams.value,
        },
      });
    }

    departmentPages.push({
      label: "My Field Reports",
      description: "Field report author workspace.",
      to: { name: "staff.field-reports.index" },
    });

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

    if (fixtureDepartmentHasOrganizerDepartmentAccess(department)) {
      sections.push({
        title: "Organization pages",
        description: "Organizer administration across departments.",
        links: [
          {
            label: "Staff",
            description: "Organizer staff intake and lead selection.",
            to: { name: "organizer.staff.index" },
          },
          {
            label: "Departments",
            description: "Organizer department administration.",
            to: { name: "organizer.departments.index" },
          },
          {
            label: "Documents",
            description:
              "Organization policies, procedures, fragments, and exports.",
            to: { name: "organizer.documents.index" },
          },
        ],
      });
    }

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
