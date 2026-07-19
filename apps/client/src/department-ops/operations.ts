import type {
  CapabilityContext,
  OperationsCenterModel,
  OperationsModule,
} from "@/department-ops/types";

export function composeOperationsModules(
  capabilities: CapabilityContext,
  equipmentOutCount: number,
): readonly OperationsModule[] {
  return [
    {
      id: "deployments",
      title: "Deployments",
      available: capabilities.hasOperations,
      unavailableReason: capabilities.hasOperations
        ? null
        : "Deployments require Department Operations capability.",
      summary: "Current deployment/location assignments for staff on shift.",
    },
    {
      id: "incidents",
      title: "Incidents",
      available: capabilities.hasIncidentCommand,
      unavailableReason: capabilities.hasIncidentCommand
        ? null
        : "Incident overview requires event-scoped Incident Command capability.",
      summary: capabilities.hasIncidentCommand
        ? "Incident overview for the event IC department."
        : "Incident overview is hidden until IC capability is granted.",
    },
    {
      id: "equipment",
      title: "Equipment",
      available: capabilities.hasEquipmentVisibility,
      unavailableReason: capabilities.hasEquipmentVisibility
        ? null
        : "Equipment overview requires equipment visibility capability.",
      summary: capabilities.hasEquipmentVisibility
        ? `${equipmentOutCount} item${equipmentOutCount === 1 ? "" : "s"} currently checked out in this department.`
        : "Equipment overview is hidden without equipment visibility.",
    },
    {
      id: "maintenance",
      title: "Maintenance",
      available: false,
      unavailableReason:
        "Maintenance tickets are an extension point until that domain is specified.",
      summary: "No maintenance module yet.",
    },
  ];
}

export function availableOperationsModules(
  center: OperationsCenterModel,
): readonly OperationsModule[] {
  return center.modules.filter((module) => module.available);
}

export function assignOperationsDeployment(
  center: OperationsCenterModel,
  assignmentId: string,
  deploymentId: string,
): OperationsCenterModel {
  if (!center.capabilities.hasOperations) {
    throw new Error("Department Operations capability is required.");
  }

  const deployment = center.deploymentOptions.find(
    (option) => option.deploymentId === deploymentId,
  );
  if (deployment === undefined) {
    throw new Error("Deployment option is not available.");
  }

  let found = false;
  const deploymentRows = center.deploymentRows.map((row) => {
    if (row.assignmentId !== assignmentId) {
      return row;
    }

    found = true;
    return {
      ...row,
      currentDeploymentId: deployment.deploymentId,
    };
  });

  if (!found) {
    throw new Error("Roster member is not available.");
  }

  return {
    ...center,
    deploymentRows,
  };
}

export function deploymentName(
  center: OperationsCenterModel,
  deploymentId: string | null,
): string {
  if (deploymentId === null) {
    return "Unassigned";
  }

  return (
    center.deploymentOptions.find(
      (option) => option.deploymentId === deploymentId,
    )?.name ?? "Unassigned"
  );
}
