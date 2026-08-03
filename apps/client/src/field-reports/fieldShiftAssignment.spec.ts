import { afterEach, describe, expect, it } from "vitest";

import {
  installFieldShiftResolver,
  resetFieldShiftResolver,
  resolveCurrentFieldShift,
} from "@/field-reports/fieldShiftAssignment";

afterEach(() => {
  resetFieldShiftResolver();
});

/*
 * The fixture resolver is gone (M18.9).
 *
 * It matched the author's staff id against four compiled-in workspaces, so for
 * every real staff member it missed and answered null — which is the answer that
 * remains. What the tests below hold is the contract the seam has to keep: an
 * honest "not on shift" by default, and a resolver that can be replaced when
 * attendance state becomes readable by the author.
 */
describe("resolveCurrentFieldShift", () => {
  it("reports nobody as on shift until a resolver can say otherwise", () => {
    expect(resolveCurrentFieldShift("staff-anyone")).toBeNull();
  });

  /*
   * The report is immutable once filed, so a wrong department and team on it are
   * permanent. Off-shift is the answer that can be corrected by context; a
   * guessed team is not.
   */
  it("guesses no department or team for an author it knows nothing about", () => {
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

  it("goes back to reporting off-shift when the resolver is removed", () => {
    installFieldShiftResolver(() => ({
      shiftId: "shift-1",
      shiftTitle: "Replaced",
      departmentId: "department-1",
      departmentLabel: "Replaced Department",
      teamId: "team-1",
      teamLabel: "Replaced Team",
    }));
    resetFieldShiftResolver();

    expect(resolveCurrentFieldShift("anyone")).toBeNull();
  });
});
