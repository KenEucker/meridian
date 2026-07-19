import type {
  DepartmentOverview,
  OverviewAssignment,
  ShiftOption,
} from "@/department-ops/types";

export function selectedShift(
  overview: DepartmentOverview,
): ShiftOption | undefined {
  return overview.shifts.find(
    (shift) => shift.shiftId === overview.selectedShiftId,
  );
}

export function selectOverviewShift(
  overview: DepartmentOverview,
  shiftId: string,
): DepartmentOverview {
  if (!overview.shifts.some((shift) => shift.shiftId === shiftId)) {
    throw new Error("Shift is not available for this department.");
  }

  return {
    ...overview,
    selectedShiftId: shiftId,
  };
}

export function overviewSummary(overview: DepartmentOverview): {
  readonly assignmentCount: number;
  readonly checkedInCount: number;
  readonly onSiteCount: number;
  readonly equipmentOutCount: number;
} {
  return {
    assignmentCount: overview.assignments.length,
    checkedInCount: overview.assignments.filter(
      (member) => member.attendanceState === "checked_in",
    ).length,
    onSiteCount: overview.onSiteCount,
    equipmentOutCount: overview.equipmentOut.length,
  };
}

export function checkedInAssignments(
  overview: DepartmentOverview,
): readonly OverviewAssignment[] {
  return overview.assignments.filter(
    (member) => member.attendanceState === "checked_in",
  );
}

export function deploymentLabel(
  overview: DepartmentOverview,
  deploymentId: string | null,
): string {
  if (deploymentId === null) {
    return "Unassigned";
  }

  return (
    overview.deploymentOptions.find(
      (option) => option.deploymentId === deploymentId,
    )?.name ?? "Unassigned"
  );
}
