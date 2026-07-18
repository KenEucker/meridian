import { describe, expect, it } from "vitest";

import {
  LOCAL_CURRENT_SHIFT_BOARD,
  attendanceStateLabel,
  checkedInRoster,
  rosterSummary,
} from "@/shift-board/currentShiftBoard";

describe("current shift board roster model (M10.1)", () => {
  it("summarizes roster and checked-in staff from derived attendance state", () => {
    expect(rosterSummary(LOCAL_CURRENT_SHIFT_BOARD)).toEqual({
      rosterCount: 3,
      checkedInCount: 2,
    });

    expect(
      checkedInRoster(LOCAL_CURRENT_SHIFT_BOARD).map(
        (member) => member.displayName,
      ),
    ).toEqual(["Local Field Author", "Sam Shiftlead"]);
  });

  it("uses human-readable attendance labels", () => {
    expect(attendanceStateLabel("scheduled")).toBe("Scheduled");
    expect(attendanceStateLabel("checked_in")).toBe("Checked in");
    expect(attendanceStateLabel("checked_out")).toBe("Checked out");
    expect(attendanceStateLabel("no_show")).toBe("No-show");
  });
});
