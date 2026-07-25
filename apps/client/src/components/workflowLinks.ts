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
 * Top-level workflows.
 *
 * A workflow is a hub someone works out of for a stretch of the event, not
 * every page they can reach. Pages that belong to a workflow are reached from
 * inside it: Shifts and Documents live under Admin, Trainings lives under
 * Planning. Staff-facing versions of those pages are reached from the Staff
 * menu instead.
 */
export function useWorkflowLinks(): ComputedRef<WorkflowLink[]> {
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );

  return computed(() => {
    const department = selectedFixtureDepartment.value;
    const links: WorkflowLink[] = [
      {
        label: "Me",
        description: "Your shifts, trainings, and event information.",
        to: { name: "staff.me" },
      },
    ];

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
 * Staff menu for department members without lead authority: the department
 * pages they can use themselves, rather than the lead workflows.
 */
export function useStaffLinks(): ComputedRef<WorkflowLink[]> {
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );

  return computed(() => {
    const department = selectedFixtureDepartment.value;

    if (!department.teams.some((team) => team.isMember)) {
      return [];
    }

    return [
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
    ];
  });
}

/**
 * Whether the Staff menu is shown in the app shell. Leads reach the same pages
 * from inside the Admin and Planning workflows.
 */
export function useShowStaffMenu(): ComputedRef<boolean> {
  const staffLinks = useStaffLinks();

  return computed(
    () =>
      staffLinks.value.length > 0 &&
      !fixtureDepartmentHasAdminAccess(selectedFixtureDepartment.value),
  );
}

/**
 * Every page the current fixture user can reach, grouped for the home screen.
 * Workflows are listed first, then the individual pages they contain and the
 * device pages that belong to no workflow.
 */
export function useNavigationSections(): ComputedRef<NavigationSection[]> {
  const workflowLinks = useWorkflowLinks();
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );

  return computed(() => {
    const department = selectedFixtureDepartment.value;
    const sections: NavigationSection[] = [
      {
        title: "Workflows",
        description: "Hubs you work out of during the event.",
        links: workflowLinks.value,
      },
    ];

    const departmentPages: WorkflowLink[] = [];

    if (fixtureDepartmentHasAdminAccess(department)) {
      departmentPages.push({
        label: "Shifts",
        description: "Create and maintain department and team shifts.",
        to: {
          name: "events.departments.shifts.index",
          params: departmentRouteParams.value,
        },
      });
      departmentPages.push({
        label: "Documents",
        description: "Department and team policies, procedures, and fragments.",
        to: {
          name: "events.departments.documents.index",
          params: departmentRouteParams.value,
        },
      });
    } else if (department.teams.some((team) => team.isMember)) {
      departmentPages.push({
        label: "Shifts",
        description: "Shifts your teams are eligible for.",
        to: {
          name: "events.departments.shifts.index",
          params: departmentRouteParams.value,
        },
      });
      departmentPages.push({
        label: "Documents",
        description: "Policies and procedures published to your department.",
        to: {
          name: "events.departments.documents.index",
          params: departmentRouteParams.value,
        },
      });
    }

    if (
      fixtureDepartmentHasAdminAccess(department) ||
      department.teams.some((team) => team.isMember)
    ) {
      departmentPages.push({
        label: "Trainings",
        description: "Department training schedule, signup, and completion.",
        to: {
          name: "events.departments.trainings.index",
          params: departmentRouteParams.value,
        },
      });
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

    departmentPages.push({
      label: "Event Info",
      description: "Directions, arrival, packing, food, and housing.",
      to: {
        name: "events.info",
        params: { eventId: departmentRouteParams.value.eventId },
      },
    });

    sections.push({
      title: "Department pages",
      description: "Pages inside your department workflows.",
      links: departmentPages,
    });

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
