import { describe, expect, it } from "vitest";

import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";
import { LOCAL_LOGISTICS_DESK } from "@/department-ops/fixtures";
import {
  addLogisticsStaffToShift,
  checkInLogisticsStaff,
  markLogisticsStaffOffSite,
  markLogisticsStaffOnSite,
  returnLogisticsEquipment,
  searchLogisticsDesk,
  selectLogisticsStaff,
  selectedLogisticsWorkspace,
} from "@/department-ops/logistics";

describe("logistics desk model", () => {
  it("searches department-scoped staff, equipment, and shifts", () => {
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
