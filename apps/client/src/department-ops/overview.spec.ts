import { describe, expect, it } from "vitest";

import { LOCAL_DEPARTMENT_OVERVIEW } from "@/department-ops/fixtures";
import {
  checkedInAssignments,
  overviewSummary,
  selectOverviewShift,
  selectedShift,
} from "@/department-ops/overview";

describe("department overview model", () => {
  it("defaults to the active day shift and summarizes attention counts", () => {
    expect(selectedShift(LOCAL_DEPARTMENT_OVERVIEW)?.title).toBe(
      "Ranger Dirt Day Shift",
    );
    expect(overviewSummary(LOCAL_DEPARTMENT_OVERVIEW)).toEqual({
      assignmentCount: 3,
      checkedInCount: 2,
      onSiteCount: 3,
      equipmentOutCount: 1,
    });
    expect(
      checkedInAssignments(LOCAL_DEPARTMENT_OVERVIEW).map(
        (member) => member.displayName,
      ),
    ).toEqual(["Local Field Author", "Sam Shiftlead"]);
  });

  it("switches the selected shift", () => {
    const updated = selectOverviewShift(
      LOCAL_DEPARTMENT_OVERVIEW,
      "99999999-9999-4999-8999-999999999998",
    );

    expect(updated.selectedShiftId).toBe(
      "99999999-9999-4999-8999-999999999998",
    );
    expect(selectedShift(updated)?.title).toBe("Ranger Dirt Swing Shift");
  });
});
