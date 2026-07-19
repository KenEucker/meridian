import type {
  PlanningAggregateRow,
  PlanningTableFilters,
  PlanningTableModel,
} from "@/department-ops/types";

export function filterPlanningRows(
  table: PlanningTableModel,
  filters: PlanningTableFilters = table.selectedFilters,
): readonly PlanningAggregateRow[] {
  return table.rows.filter((row) => {
    if (filters.teamId !== null && row.teamId !== filters.teamId) {
      return false;
    }

    if (
      filters.date !== null &&
      dateKeyForTimestamp(row.startsAt, table.context.timeZone) !== filters.date
    ) {
      return false;
    }

    return true;
  });
}

export function planningSummary(table: PlanningTableModel): {
  readonly shiftCount: number;
  readonly underTargetCount: number;
  readonly activeCount: number;
  readonly completedCount: number;
  readonly actualHours: number;
  readonly plannedHours: number;
} {
  const rows = filterPlanningRows(table);

  return {
    shiftCount: rows.length,
    underTargetCount: rows.filter(
      (row) =>
        row.capacity !== null && row.signedUpOrAssignedCount < row.capacity,
    ).length,
    activeCount: rows.filter((row) => row.lifecycle === "active").length,
    completedCount: rows.filter((row) => row.lifecycle === "completed").length,
    actualHours: sumHours(rows, "actualHours"),
    plannedHours: sumHours(rows, "plannedHours"),
  };
}

export function capacityLabel(capacity: number | null): string {
  return capacity === null ? "No target" : String(capacity);
}

export function signedVarianceLabel(hours: number): string {
  if (hours === 0) {
    return "0";
  }

  return hours > 0 ? `+${formatHours(hours)}` : formatHours(hours);
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

export function dateKeyForTimestamp(timestamp: string, timeZone: string): string {
  const parts = new Intl.DateTimeFormat("en-US", {
    day: "2-digit",
    month: "2-digit",
    timeZone,
    year: "numeric",
  }).formatToParts(new Date(timestamp));

  const part = (type: "day" | "month" | "year"): string => {
    const value = parts.find((candidate) => candidate.type === type)?.value;

    if (!value) {
      throw new Error(`Could not format ${type} for Planning Table date filter.`);
    }

    return value;
  };

  return `${part("year")}-${part("month")}-${part("day")}`;
}

function sumHours(
  rows: readonly PlanningAggregateRow[],
  field: "actualHours" | "plannedHours",
): number {
  return Number(
    rows
      .reduce((total, row) => total + row[field], 0)
      .toFixed(1),
  );
}

function formatHours(hours: number): string {
  return Number.isInteger(hours) ? String(hours) : hours.toFixed(1);
}
