import { describe, expect, it } from "vitest";

import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";
import { LOCAL_LOGISTICS_DESK } from "@/department-ops/fixtures";
import {
  addLogisticsStaffToShift,
  checkInLogisticsStaff,
  checkOutLogisticsEquipment,
  checkOutLogisticsStaff,
  currentLogisticsShifts,
  isShiftCurrentlyGoing,
  logisticsShiftSections,
  logisticsStaffOnShift,
  markLogisticsStaffOffSite,
  markLogisticsStaffOnSite,
  returnLogisticsEquipment,
  searchLogisticsDesk,
  selectLogisticsHit,
  selectLogisticsStaff,
  selectedLogisticsWorkspace,
} from "@/department-ops/logistics";

describe("logistics desk model", () => {
  it("lists shifts currently going within a 15-minute start/end window", () => {
    // Fixture asOf is 2027-07-04T18:00Z; day shift runs 16:00-22:00.
    expect(
      currentLogisticsShifts(LOCAL_LOGISTICS_DESK).map((shift) => shift.title),
    ).toEqual(["Ranger Dirt Day Shift"]);

    const day = LOCAL_LOGISTICS_DESK.searchableShifts[0]!;
    expect(isShiftCurrentlyGoing(day, "2027-07-04T15:46:00.000Z")).toBe(true);
    expect(isShiftCurrentlyGoing(day, "2027-07-04T15:44:00.000Z")).toBe(false);
    expect(isShiftCurrentlyGoing(day, "2027-07-04T22:14:00.000Z")).toBe(true);
    expect(isShiftCurrentlyGoing(day, "2027-07-04T22:16:00.000Z")).toBe(false);

    const swing = LOCAL_LOGISTICS_DESK.searchableShifts[1]!;
    expect(isShiftCurrentlyGoing(swing, "2027-07-04T21:46:00.000Z")).toBe(true);
    expect(isShiftCurrentlyGoing(swing, "2027-07-04T21:44:00.000Z")).toBe(
      false,
    );
  });

  it("lists staff checked in on a shift, oldest shift start first", () => {
    const onShift = logisticsStaffOnShift(LOCAL_LOGISTICS_DESK);

    expect(onShift.length).toBeGreaterThan(0);
    expect(
      onShift.every((member) => member.displayName.length > 0 && member.shiftId),
    ).toBe(true);

    const startTimes = onShift.map((member) => member.startsAt);
    expect([...startTimes].sort()).toEqual(startTimes);
  });

  it("excludes staff who are only scheduled or already checked out", () => {
    const onShiftIds = new Set(
      logisticsStaffOnShift(LOCAL_LOGISTICS_DESK).map(
        (member) => `${member.staffId}:${member.shiftId}`,
      ),
    );

    for (const workspace of Object.values(LOCAL_LOGISTICS_DESK.staffWorkspaces)) {
      for (const card of workspace.shiftCards) {
        const key = `${workspace.staffId}:${card.shiftId}`;

        expect(onShiftIds.has(key)).toBe(card.attendanceState === "checked_in");
      }
    }
  });

  it("drops a staff member from the roster once they are checked out", () => {
    const before = logisticsStaffOnShift(LOCAL_LOGISTICS_DESK);
    const member = before[0]!;

    const after = logisticsStaffOnShift(
      checkOutLogisticsStaff(
        LOCAL_LOGISTICS_DESK,
        member.staffId,
        member.shiftId,
      ),
    );

    expect(
      after.some(
        (entry) =>
          entry.staffId === member.staffId && entry.shiftId === member.shiftId,
      ),
    ).toBe(false);
    expect(after).toHaveLength(before.length - 1);
  });

  it("reports open equipment and what each roster action can do", () => {
    const onShift = logisticsStaffOnShift(LOCAL_LOGISTICS_DESK);

    for (const member of onShift) {
      const workspace = LOCAL_LOGISTICS_DESK.staffWorkspaces[member.staffId]!;

      expect(member.openEquipmentCount).toBe(workspace.openEquipment.length);
      expect(member.canCheckOutEquipment).toBe(
        workspace.availableEquipment.length > 0,
      );
    }
  });

  it("searches department-scoped staff, equipment, and shifts", () => {
    expect(LOCAL_LOGISTICS_DESK.searchCache.scopeLabel).toBe(
      "Idaho Decompression 2026 / Rangers",
    );
    expect(LOCAL_LOGISTICS_DESK.searchCache.state).toBe("offline_usable");
    expect(LOCAL_LOGISTICS_DESK.searchCache.includes).toEqual([
      "staff",
      "equipment",
      "shift",
    ]);

    const hits = searchLogisticsDesk(LOCAL_LOGISTICS_DESK, "vera");

    expect(hits.map((hit) => hit.kind)).toEqual(["staff"]);
    expect(hits[0]?.label).toBe("Vera Staff");
    expect(
      searchLogisticsDesk(LOCAL_LOGISTICS_DESK, "radio").some(
        (hit) => hit.kind === "equipment",
      ),
    ).toBe(true);
    expect(
      searchLogisticsDesk(LOCAL_LOGISTICS_DESK, "swing").some(
        (hit) => hit.kind === "shift",
      ),
    ).toBe(true);
  });

  it("opens staff context from equipment and shift search hits", () => {
    const radioHit = searchLogisticsDesk(LOCAL_LOGISTICS_DESK, "RDO-12")[0]!;
    const radioDesk = selectLogisticsHit(LOCAL_LOGISTICS_DESK, radioHit);

    expect(radioDesk.selectedSearchContext?.kind).toBe("equipment");
    expect(selectedLogisticsWorkspace(radioDesk)?.displayName).toBe(
      "Local Field Author",
    );

    const shiftHit = searchLogisticsDesk(LOCAL_LOGISTICS_DESK, "swing")[0]!;
    const shiftDesk = selectLogisticsHit(LOCAL_LOGISTICS_DESK, shiftHit);

    expect(shiftDesk.selectedSearchContext?.kind).toBe("shift");
    expect(shiftDesk.selectedSearchContext?.relatedStaffIds).toContain(
      LOCAL_FIELD_FIXTURE.staffId,
    );
    expect(shiftDesk.selectedSearchContext?.relatedStaffIds).toContain(
      "33333333-3333-4333-8333-333333333336",
    );
    expect(selectedLogisticsWorkspace(shiftDesk)).toBeNull();
  });

  it("groups a staff workspace by active, upcoming, and outgoing shift context", () => {
    const workspace = selectedLogisticsWorkspace(
      selectLogisticsStaff(LOCAL_LOGISTICS_DESK, LOCAL_FIELD_FIXTURE.staffId),
    )!;
    const sections = logisticsShiftSections(workspace);

    expect(sections.active.map((card) => card.title)).toEqual([
      "Ranger Dirt Day Shift",
    ]);
    expect(sections.upcoming.map((card) => card.title)).toEqual([
      "Ranger Dirt Swing Shift",
    ]);
    expect(sections.outgoing.map((card) => card.title)).toEqual([
      "Ranger Command Overnight",
    ]);
  });

  it("marks staff on-site and enables check-in", () => {
    const updated = markLogisticsStaffOnSite(
      LOCAL_LOGISTICS_DESK,
      "33333333-3333-4333-8333-333333333334",
    );
    const workspace = selectedLogisticsWorkspace(
      selectLogisticsStaff(updated, "33333333-3333-4333-8333-333333333334"),
    );

    expect(workspace?.presenceState).toBe("on_site");
    expect(workspace?.shiftCards[0]?.canCheckIn).toBe(true);
  });

  it("blocks off-site while checked in or holding equipment", () => {
    expect(() =>
      markLogisticsStaffOffSite(
        LOCAL_LOGISTICS_DESK,
        LOCAL_FIELD_FIXTURE.staffId,
      ),
    ).toThrow("checked out");
  });

  it("checks staff in with equipment handoff and returns equipment", () => {
    const onSite = markLogisticsStaffOnSite(
      LOCAL_LOGISTICS_DESK,
      "33333333-3333-4333-8333-333333333334",
    );
    const checkedIn = checkInLogisticsStaff(
      onSite,
      "33333333-3333-4333-8333-333333333334",
      "99999999-9999-4999-8999-999999999999",
      "2027-07-04T18:05:00.000Z",
      ["equipment-safety-vest"],
    );
    const workspace = selectedLogisticsWorkspace(checkedIn);

    expect(workspace?.shiftCards[0]?.attendanceState).toBe("checked_in");
    expect(workspace?.openEquipment.map((item) => item.name)).toContain(
      "Safety Vest",
    );

    const returned = returnLogisticsEquipment(
      checkedIn,
      "33333333-3333-4333-8333-333333333334",
      workspace!.openEquipment[0]!.checkoutId!,
      "returned",
    );

    expect(
      selectedLogisticsWorkspace(returned)?.openEquipment,
    ).toHaveLength(0);
  });

  it("checks out additional equipment after staff is already checked in", () => {
    const onSite = markLogisticsStaffOnSite(
      LOCAL_LOGISTICS_DESK,
      "33333333-3333-4333-8333-333333333334",
    );
    const checkedIn = checkInLogisticsStaff(
      onSite,
      "33333333-3333-4333-8333-333333333334",
      "99999999-9999-4999-8999-999999999999",
      "2027-07-04T18:05:00.000Z",
    );
    const equipmentOut = checkOutLogisticsEquipment(
      checkedIn,
      "33333333-3333-4333-8333-333333333334",
      "2027-07-04T18:35:00.000Z",
      ["equipment-radio-13", "equipment-radio-14"],
    );
    const workspace = selectedLogisticsWorkspace(equipmentOut);

    expect(workspace?.openEquipment.map((item) => item.name)).toEqual([
      "Radio 13",
      "Radio 14",
    ]);
    expect(workspace?.availableEquipment.map((item) => item.name)).toEqual([
      "Safety Vest",
      "Radio 15",
    ]);
    expect(
      searchLogisticsDesk(equipmentOut, "RDO-13")[0]?.detail,
    ).toContain("Vera Staff");
    expect(
      searchLogisticsDesk(equipmentOut, "RDO-14")[0]?.detail,
    ).toContain("Vera Staff");
  });

  it("adds an on-site unscheduled staff member to a shift", () => {
    const updated = addLogisticsStaffToShift(
      LOCAL_LOGISTICS_DESK,
      "33333333-3333-4333-8333-333333333336",
      "99999999-9999-4999-8999-999999999999",
      "local-assignment-ari-day",
    );
    const workspace = selectedLogisticsWorkspace(updated);
    const dayCard = workspace?.shiftCards.find(
      (card) => card.shiftId === "99999999-9999-4999-8999-999999999999",
    );

    expect(dayCard?.assignmentId).toBe("local-assignment-ari-day");
    expect(dayCard?.canCheckIn).toBe(true);
    expect(dayCard?.canAddToShift).toBe(false);
  });
});
