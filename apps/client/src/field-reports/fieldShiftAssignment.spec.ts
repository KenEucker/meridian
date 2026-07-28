import { afterEach, describe, expect, it } from "vitest";

import { FIXTURE_RANGERS_DIRT_TEAM_ID } from "@/department-teams/fixtureDepartmentAccess";
import {
  installFieldShiftResolver,
  resetFieldShiftResolver,
  resolveCurrentFieldShift,
} from "@/field-reports/fieldShiftAssignment";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

afterEach(() => {
  resetFieldShiftResolver();
});

describe("resolveCurrentFieldShift", () => {
  it("returns the shift a staff member is checked into", () => {
    expect(resolveCurrentFieldShift(LOCAL_FIELD_FIXTURE.staffId)).toMatchObject({
      teamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
      teamLabel: "Dirt",
      departmentLabel: "Rangers",
    });
  });

  it("does not count a running shift the staff member has not checked into", () => {
    // Vera is assigned to the active day shift and still `scheduled`. Being on
    // the roster is not the same as working, and a report filed now is not
    // that shift's.
    expect(
      resolveCurrentFieldShift("33333333-3333-4333-8333-333333333334"),
    ).toBeNull();
  });

  it("does not count a shift with no attendance record at all", () => {
    expect(
      resolveCurrentFieldShift("33333333-3333-4333-8333-333333333336"),
    ).toBeNull();
  });

  it("returns null for a staff member with no workspace", () => {
    expect(resolveCurrentFieldShift("staff-unknown")).toBeNull();
  });

  it("can be replaced, which is how real attendance state will arrive", () => {
    installFieldShiftResolver(() => ({
      shiftId: "shift-1",
      shiftTitle: "Replaced",
      departmentId: "department-1",
      departmentLabel: "Replaced Department",
      teamId: "team-1",
      teamLabel: "Replaced Team",
    }));

    expect(resolveCurrentFieldShift("anyone")).toMatchObject({
      teamLabel: "Replaced Team",
    });
  });
});
