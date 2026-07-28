// What a staff member is working on right now, for Field Report attribution.
//
// A Field Report records the department and team the author was working for
// when they filed it. There are only two ways that can be true:
//
//   A. They are on shift. The report belongs to the team whose shift they are
//      checked into, and to that team's department. The department switcher
//      does not enter into it — you cannot be working a Rangers shift and file
//      the report against Gate.
//   B. They are not on shift. There is no team to attribute the report to, so
//      it takes the department they have selected and records the team as
//      off-shift.
//
// "On shift" means checked in to a shift that is currently running. A shift
// that is running but that the staff member has not checked into is not being
// worked, and a completed shift they checked out of is over. Both are case B.
//
// Real shift and attendance state arrives with auth and event selection; this
// module reads the local development fixtures until then, and is the seam that
// gets replaced rather than rewritten.

import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_LOGISTICS_DESK,
} from "@/department-ops/fixtures";

/** The team label recorded when a report is filed outside any shift. */
export const OFF_SHIFT_TEAM_LABEL = "Off-shift";

export interface FieldShiftAssignment {
  readonly shiftId: string;
  readonly shiftTitle: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly teamId: string;
  readonly teamLabel: string;
}

export type FieldShiftResolver = (
  staffId: string,
) => FieldShiftAssignment | null;

const fixtureShiftResolver: FieldShiftResolver = (staffId) => {
  const workspace = LOCAL_LOGISTICS_DESK.staffWorkspaces[staffId];

  if (workspace === undefined) {
    return null;
  }

  const card = workspace.shiftCards.find(
    (candidate) =>
      candidate.lifecycle === "active" &&
      candidate.attendanceState === "checked_in",
  );

  if (card === undefined) {
    return null;
  }

  return {
    shiftId: card.shiftId,
    shiftTitle: card.title,
    departmentId: LOCAL_DEPARTMENT_OPS_CONTEXT.departmentId,
    departmentLabel: LOCAL_DEPARTMENT_OPS_CONTEXT.departmentLabel,
    teamId: card.teamId,
    teamLabel: card.teamLabel,
  };
};

let installedResolver: FieldShiftResolver = fixtureShiftResolver;

/** Replace the shift resolver (real attendance state, or a test). */
export function installFieldShiftResolver(resolver: FieldShiftResolver): void {
  installedResolver = resolver;
}

/** Restore the local development fixture resolver. */
export function resetFieldShiftResolver(): void {
  installedResolver = fixtureShiftResolver;
}

/**
 * The shift this staff member is currently checked into, or `null` when they
 * are not working right now.
 */
export function resolveCurrentFieldShift(
  staffId: string,
): FieldShiftAssignment | null {
  return installedResolver(staffId);
}
