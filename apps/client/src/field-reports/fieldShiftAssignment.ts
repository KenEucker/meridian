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
// The default answer is case B, and stays that way until something installs a
// resolver that can answer case A (M18.9).
//
// Until then this module read `department-ops/fixtures.ts` and matched the
// author's staff id against the fixture's four workspaces. For every real staff
// member that lookup missed and returned null, so case B is what real reports
// were already attributed with — the fixture only ever answered for fixture
// people. Removing it changes nothing about a report a real author files and
// stops the client holding a second, invented copy of who is on shift.
//
// Case A needs attendance state, which is not the same question as "which shifts
// am I signed up for". The staff shift board answers the second and an ordinary
// staff member may read it; the first lives on the attendance records behind the
// Logistics Desk, which they may not. `installFieldShiftResolver` is the seam
// that closes the gap when a staff-readable attendance read exists.

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

/**
 * The resolver in force until attendance state is readable by the author.
 *
 * "Not on shift" rather than a guess. A wrong department and team on an
 * immutable report is worse than an honest off-shift one: the report cannot be
 * edited afterwards, so a bad attribution is permanent.
 */
const offShiftResolver: FieldShiftResolver = () => null;

let installedResolver: FieldShiftResolver = offShiftResolver;

/** Replace the shift resolver (real attendance state, or a test). */
export function installFieldShiftResolver(resolver: FieldShiftResolver): void {
  installedResolver = resolver;
}

/** Restore the default off-shift resolver. */
export function resetFieldShiftResolver(): void {
  installedResolver = offShiftResolver;
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
