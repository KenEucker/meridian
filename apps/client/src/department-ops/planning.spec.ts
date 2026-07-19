import { describe, expect, it } from "vitest";

import { LOCAL_PLANNING_TABLE } from "@/department-ops/fixtures";
import {
  assertNoStaffIdentities,
  capacityLabel,
  planningSummary,
} from "@/department-ops/planning";

describe("planning table model", () => {
  it("summarizes identity-free plan versus actual rows", () => {
    expect(planningSummary(LOCAL_PLANNING_TABLE)).toEqual({
      shiftCount: 2,
      underTargetCount: 2,
      activeCount: 1,
    });
    expect(capacityLabel(null)).toBe("No target");
    expect(capacityLabel(4)).toBe("4");
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
