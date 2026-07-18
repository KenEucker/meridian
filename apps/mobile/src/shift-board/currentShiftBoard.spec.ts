import { describe, expect, it } from "vitest";

import {
  LOCAL_CURRENT_SHIFT_BOARD,
  addUnscheduledRosterMember,
  attendanceStateLabel,
  checkedInRoster,
  eligibleUnscheduledCandidates,
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

  it("adds an eligible unscheduled staff member to the roster view model", () => {
    expect(
      eligibleUnscheduledCandidates(LOCAL_CURRENT_SHIFT_BOARD).map(
        (candidate) => candidate.displayName,
      ),
    ).toEqual(["Ari Ranger"]);

    const updated = addUnscheduledRosterMember(
      LOCAL_CURRENT_SHIFT_BOARD,
      "33333333-3333-4333-8333-333333333336",
      "local-unscheduled-ari",
    );

    expect(rosterSummary(updated)).toEqual({
      rosterCount: 4,
      checkedInCount: 2,
    });
    expect(updated.roster.at(-1)).toMatchObject({
      assignmentId: "local-unscheduled-ari",
      displayName: "Ari Ranger",
      attendanceState: "scheduled",
      checkedInAt: null,
    });
    expect(eligibleUnscheduledCandidates(updated)).toEqual([]);
  });
});
