import type { PlanningAggregateRow, PlanningTableModel } from "@/department-ops/types";

export function filterPlanningRows(
  table: PlanningTableModel,
  teamId: string | null,
): readonly PlanningAggregateRow[] {
  if (teamId === null) {
    return table.rows;
  }

  return table.rows.filter((row) => row.teamLabel === teamId);
}

export function planningSummary(table: PlanningTableModel): {
  readonly shiftCount: number;
  readonly underTargetCount: number;
  readonly activeCount: number;
} {
  return {
    shiftCount: table.rows.length,
    underTargetCount: table.rows.filter(
      (row) =>
        row.capacity !== null && row.signedUpOrAssignedCount < row.capacity,
    ).length,
    activeCount: table.rows.filter((row) => row.lifecycle === "active").length,
  };
}

export function capacityLabel(capacity: number | null): string {
  return capacity === null ? "No target" : String(capacity);
}

export function assertNoStaffIdentities(table: PlanningTableModel): void {
  const serialized = JSON.stringify(table.rows);
  const forbidden = [
    "displayName",
    "staffId",
    "handle",
    "signupId",
    "assignmentId",
  ];

  for (const key of forbidden) {
    if (serialized.includes(`"${key}"`)) {
      throw new Error(`Planning Table must not expose identity field ${key}.`);
    }
  }
}
