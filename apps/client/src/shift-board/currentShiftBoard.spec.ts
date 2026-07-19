import { describe, expect, it } from "vitest";

import {
  LOCAL_CURRENT_SHIFT_BOARD,
  addEquipmentAndCheckoutToStaff,
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
  markStaffOffSite,
  markStaffOnSite,
  presenceStateLabel,
  presenceSummary,
  returnEquipmentFromStaff,
  rosterSummary,
} from "@/shift-board/currentShiftBoard";

describe("current department board model (M10 course correction)", () => {
  it("summarizes shift assignments and department presence", () => {
    expect(rosterSummary(LOCAL_CURRENT_SHIFT_BOARD)).toEqual({
      rosterCount: 3,
      checkedInCount: 2,
    });
    expect(presenceSummary(LOCAL_CURRENT_SHIFT_BOARD)).toEqual({
      onSiteCount: 3,
      offSiteCount: 2,
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

  it("uses human-readable department presence labels", () => {
    expect(presenceStateLabel("on_site")).toBe("On-site");
    expect(presenceStateLabel("off_site")).toBe("Off-site");
  });

  it("uses human-readable equipment state labels", () => {
    expect(equipmentStateLabel("available")).toBe("Available");
    expect(equipmentStateLabel("checked_out")).toBe("Checked out");
    expect(equipmentStateLabel("returned")).toBe("Returned");
    expect(equipmentStateLabel("missing")).toBe("Missing");
    expect(equipmentStateLabel("damaged")).toBe("Damaged");
  });

  it("adds an on-site eligible staff member to the shift view model", () => {
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

  it("requires department staff to be on-site before they become shift candidates", () => {
    expect(
      eligibleUnscheduledCandidates(LOCAL_CURRENT_SHIFT_BOARD).map(
        (candidate) => candidate.displayName,
      ),
    ).not.toContain("Bea Ranger");

    const updated = markStaffOnSite(
      LOCAL_CURRENT_SHIFT_BOARD,
      "33333333-3333-4333-8333-333333333337",
    );

    expect(
      eligibleUnscheduledCandidates(updated).map(
        (candidate) => candidate.displayName,
      ),
    ).toContain("Bea Ranger");
  });

  it("blocks off-site status while staff are checked into a shift", () => {
    expect(() =>
      markStaffOffSite(
        LOCAL_CURRENT_SHIFT_BOARD,
        "33333333-3333-4333-8333-333333333335",
      ),
    ).toThrow("checked out of their shift");
  });

  it("blocks off-site status while staff still hold equipment", () => {
    const withAriAssigned = addUnscheduledRosterMember(
      LOCAL_CURRENT_SHIFT_BOARD,
      "33333333-3333-4333-8333-333333333336",
      "local-unscheduled-ari",
    );
    const withEquipment = checkoutEquipmentToStaff(
      withAriAssigned,
      "equipment-safety-vest",
      "local-unscheduled-ari",
      "local-equipment-checkout-safety-vest-ari",
      "2027-07-04T16:30:00.000Z",
    );

    expect(() =>
      markStaffOffSite(
        withEquipment,
        "33333333-3333-4333-8333-333333333336",
      ),
    ).toThrow("Equipment must be returned");

    const returned = returnEquipmentFromStaff(
      withEquipment,
      "local-equipment-checkout-safety-vest-ari",
      "returned",
      "2027-07-04T17:00:00.000Z",
    );

    expect(
      presenceSummary(
        markStaffOffSite(returned, "33333333-3333-4333-8333-333333333336"),
      ).offSiteCount,
    ).toBe(3);
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

  it("adds equipment on the fly and checks it out immediately", () => {
    const updated = addEquipmentAndCheckoutToStaff(
      LOCAL_CURRENT_SHIFT_BOARD,
      {
        equipmentItemId: "local-equipment-radio-14",
        name: "Radio 14",
        assetTag: "RDO-14",
      },
      "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
      "local-equipment-checkout-radio-14",
      "2027-07-04T16:45:00.000Z",
    );

    expect(equipmentSummary(updated)).toEqual({
      checkedOutCount: 2,
    });
    expect(
      checkedOutEquipment(updated).map((item) => ({
        itemName: item.itemName,
        assetTag: item.assetTag,
        staffName: item.staffName,
      })),
    ).toContainEqual({
      itemName: "Radio 14",
      assetTag: "RDO-14",
      staffName: "Vera Staff",
    });
    expect(updated.equipmentItems.at(-1)).toMatchObject({
      equipmentItemId: "local-equipment-radio-14",
      name: "Radio 14",
      assetTag: "RDO-14",
      status: "checked_out",
    });
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
