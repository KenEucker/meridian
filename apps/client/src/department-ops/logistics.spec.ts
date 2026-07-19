import { describe, expect, it } from "vitest";

import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";
import { LOCAL_LOGISTICS_DESK } from "@/department-ops/fixtures";
import {
  addLogisticsStaffToShift,
  checkInLogisticsStaff,
  checkOutLogisticsEquipment,
  logisticsShiftSections,
  markLogisticsStaffOffSite,
  markLogisticsStaffOnSite,
  returnLogisticsEquipment,
  searchLogisticsDesk,
  selectLogisticsHit,
  selectLogisticsStaff,
  selectedLogisticsWorkspace,
} from "@/department-ops/logistics";

describe("logistics desk model", () => {
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
