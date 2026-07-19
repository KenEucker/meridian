import { describe, expect, it } from "vitest";

import { LOCAL_PLANNING_TABLE } from "@/department-ops/fixtures";
import {
  assertNoStaffIdentities,
  capacityLabel,
  dateKeyForTimestamp,
  filterPlanningRows,
  planningSummary,
  signedVarianceLabel,
} from "@/department-ops/planning";

describe("planning table model", () => {
  it("summarizes identity-free plan versus actual rows", () => {
    expect(planningSummary(LOCAL_PLANNING_TABLE)).toEqual({
      shiftCount: 3,
      underTargetCount: 2,
      activeCount: 1,
      completedCount: 1,
      actualHours: 17.7,
      plannedHours: 54,
    });
    expect(capacityLabel(null)).toBe("No target");
    expect(capacityLabel(4)).toBe("4");
    expect(signedVarianceLabel(1.5)).toBe("+1.5");
    expect(signedVarianceLabel(-18)).toBe("-18");
  });

  it("narrows aggregates by team id and event-local date", () => {
    const commandTeam = LOCAL_PLANNING_TABLE.availableTeams.find(
      (team) => team.teamLabel === "Command",
    );

    expect(commandTeam).toBeTruthy();
    expect(
      filterPlanningRows(LOCAL_PLANNING_TABLE, {
        teamId: commandTeam!.teamId,
        date: null,
      }).map((row) => row.title),
    ).toEqual(["Ranger Command Overnight"]);

    expect(
      filterPlanningRows(LOCAL_PLANNING_TABLE, {
        teamId: null,
        date: "2027-07-04",
      }).map((row) => row.title),
    ).toEqual(["Ranger Dirt Day Shift", "Ranger Dirt Swing Shift"]);
    expect(
      dateKeyForTimestamp(
        LOCAL_PLANNING_TABLE.rows[2]!.startsAt,
        LOCAL_PLANNING_TABLE.context.timeZone,
      ),
    ).toBe("2027-07-03");
  });

  it("rejects identity-bearing planning payloads", () => {
    expect(() => assertNoStaffIdentities(LOCAL_PLANNING_TABLE)).not.toThrow();

    const leaked = {
      ...LOCAL_PLANNING_TABLE,
      rows: [
        {
          ...LOCAL_PLANNING_TABLE.rows[0],
          displayName: "Vera Staff",
        },
      ],
    };

    expect(() =>
      assertNoStaffIdentities(
        leaked as unknown as typeof LOCAL_PLANNING_TABLE,
      ),
    ).toThrow("displayName");
  });
});
