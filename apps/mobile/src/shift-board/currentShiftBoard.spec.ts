import { describe, expect, it } from "vitest";

import {
  LOCAL_CURRENT_SHIFT_BOARD,
  addUnscheduledRosterMember,
  assignCurrentDeployment,
  attendanceStateLabel,
  checkedInRoster,
  checkedOutEquipment,
  checkoutEquipmentToStaff,
  checkoutReadyEquipment,
  deploymentLabel,
  equipmentStateLabel,
  equipmentSummary,
  eligibleUnscheduledCandidates,
  returnEquipmentFromStaff,
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

  it("uses human-readable equipment state labels", () => {
    expect(equipmentStateLabel("available")).toBe("Available");
    expect(equipmentStateLabel("checked_out")).toBe("Checked out");
    expect(equipmentStateLabel("returned")).toBe("Returned");
    expect(equipmentStateLabel("missing")).toBe("Missing");
    expect(equipmentStateLabel("damaged")).toBe("Damaged");
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
      currentDeploymentId: null,
    });
    expect(eligibleUnscheduledCandidates(updated)).toEqual([]);
  });

  it("assigns and moves the current deployment for a roster member", () => {
    expect(
      deploymentLabel(
        LOCAL_CURRENT_SHIFT_BOARD,
        LOCAL_CURRENT_SHIFT_BOARD.roster[1].currentDeploymentId,
      ),
    ).toBe("Unassigned");

    const updated = assignCurrentDeployment(
      LOCAL_CURRENT_SHIFT_BOARD,
      "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
      "deployment-hq-runner",
    );

    expect(updated.roster[1].currentDeploymentId).toBe(
      "deployment-hq-runner",
    );
    expect(deploymentLabel(updated, updated.roster[1].currentDeploymentId)).toBe(
      "HQ Runner",
    );
    expect(LOCAL_CURRENT_SHIFT_BOARD.roster[1].currentDeploymentId).toBeNull();
  });

  it("shows checked-out equipment and checkout-ready equipment", () => {
    expect(equipmentSummary(LOCAL_CURRENT_SHIFT_BOARD)).toEqual({
      checkedOutCount: 1,
    });
    expect(
      checkedOutEquipment(LOCAL_CURRENT_SHIFT_BOARD).map((item) => ({
        itemName: item.itemName,
        staffName: item.staffName,
        status: item.status,
      })),
    ).toEqual([
      {
        itemName: "Radio 12",
        staffName: "Local Field Author",
        status: "checked_out",
      },
    ]);
    expect(
      checkoutReadyEquipment(LOCAL_CURRENT_SHIFT_BOARD).map(
        (item) => item.name,
      ),
    ).toEqual(["Safety Vest", "Shift Flag"]);
  });

  it("checks equipment out to an individual roster member", () => {
    const updated = checkoutEquipmentToStaff(
      LOCAL_CURRENT_SHIFT_BOARD,
      "equipment-safety-vest",
      "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
      "local-equipment-checkout-safety-vest",
      "2027-07-04T16:30:00.000Z",
    );

    expect(equipmentSummary(updated)).toEqual({
      checkedOutCount: 2,
    });
    expect(
      checkedOutEquipment(updated).map((item) => ({
        itemName: item.itemName,
        staffName: item.staffName,
      })),
    ).toContainEqual({
      itemName: "Safety Vest",
      staffName: "Vera Staff",
    });
    expect(
      updated.equipmentItems.find(
        (item) => item.equipmentItemId === "equipment-safety-vest",
      )?.status,
    ).toBe("checked_out");
  });

  it("checks equipment back in with a return state", () => {
    const updated = returnEquipmentFromStaff(
      LOCAL_CURRENT_SHIFT_BOARD,
      "equipment-checkout-radio-12",
      "damaged",
      "2027-07-04T21:50:00.000Z",
    );

    expect(equipmentSummary(updated)).toEqual({
      checkedOutCount: 0,
    });
    expect(
      updated.equipmentItems.find(
        (item) => item.equipmentItemId === "equipment-radio-12",
      )?.status,
    ).toBe("damaged");
    expect(updated.equipmentCheckouts[0]).toMatchObject({
      checkoutId: "equipment-checkout-radio-12",
      returnedAt: "2027-07-04T21:50:00.000Z",
      returnCondition: "damaged",
    });
  });
});
