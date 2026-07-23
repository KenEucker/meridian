import { computed, type ComputedRef } from "vue";

import {
  fixtureDepartmentHasAdminAccess,
  selectedFixtureDepartment,
  selectedFixtureDepartmentRouteParams,
} from "@/department-teams/fixtureDepartmentAccess";

export type WorkflowLink = {
  readonly label: string;
  readonly to: {
    readonly name: string;
    readonly params?: Record<string, string>;
  };
};

export function useWorkflowLinks(): ComputedRef<WorkflowLink[]> {
  const departmentRouteParams = computed(
    () => selectedFixtureDepartmentRouteParams.value,
  );

  return computed(() => {
    const department = selectedFixtureDepartment.value;
    const links: WorkflowLink[] = [{ label: "Me", to: { name: "staff.me" } }];

    if (department.isDepartmentLead) {
      links.push({
        label: "Overview",
        to: {
          name: "events.departments.overview",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasPlanning) {
      links.push({
        label: "Planning",
        to: {
          name: "events.departments.planning",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasLogistics) {
      links.push({
        label: "Logistics",
        to: {
          name: "events.departments.logistics",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasOperations) {
      links.push({
        label: "Operations",
        to: {
          name: "events.departments.operations",
          params: departmentRouteParams.value,
        },
      });
    }

    if (department.capabilities.hasIncidentCommand) {
      links.push({ label: "Incidents", to: { name: "ims.incidents.index" } });
    }

    if (department.capabilities.hasIncidentCommand) {
      links.push({
        label: "Reports",
        to: { name: "ims.field-reports.index" },
      });
    }

    if (fixtureDepartmentHasAdminAccess(department)) {
      links.push({
        label: "Documents",
        to: {
          name: "events.departments.documents.index",
          params: departmentRouteParams.value,
        },
      });
      links.push({
        label: "Admin",
        to: {
          name: "events.departments.teams.index",
          params: departmentRouteParams.value,
        },
      });
    }

    return links;
  });
}
