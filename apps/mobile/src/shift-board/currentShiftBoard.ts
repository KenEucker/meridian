import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

export const SHIFT_ATTENDANCE_STATES = [
  "scheduled",
  "checked_in",
  "checked_out",
  "no_show",
  "excused",
  "corrected",
] as const;

export type ShiftAttendanceState = (typeof SHIFT_ATTENDANCE_STATES)[number];

export interface ShiftBoardRosterMember {
  readonly assignmentId: string;
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
  readonly attendanceState: ShiftAttendanceState;
  readonly checkedInAt: string | null;
}

export interface UnscheduledStaffCandidate {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
}

export interface CurrentShiftBoard {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly teamId: string;
  readonly teamLabel: string;
  readonly shiftId: string;
  readonly shiftTitle: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly timeZone: string;
  readonly roster: readonly ShiftBoardRosterMember[];
  readonly unscheduledCandidates: readonly UnscheduledStaffCandidate[];
}

export const LOCAL_CURRENT_SHIFT_BOARD: CurrentShiftBoard = {
  eventId: LOCAL_FIELD_FIXTURE.eventId,
  eventLabel: LOCAL_FIELD_FIXTURE.eventLabel,
  departmentId: "66666666-6666-4666-8666-666666666666",
  departmentLabel: "Rangers",
  teamId: "77777777-7777-4777-8777-777777777777",
  teamLabel: "Dirt",
  shiftId: "99999999-9999-4999-8999-999999999999",
  shiftTitle: "Ranger Dirt Day Shift",
  startsAt: "2027-07-04T16:00:00.000Z",
  endsAt: "2027-07-04T22:00:00.000Z",
  timeZone: "America/Los_Angeles",
  roster: [
    {
      assignmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1",
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      displayName: "Local Field Author",
      handle: "local-field-author",
      teamLabel: "Dirt",
      attendanceState: "checked_in",
      checkedInAt: "2027-07-04T15:52:00.000Z",
    },
    {
      assignmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
      staffId: "33333333-3333-4333-8333-333333333334",
      displayName: "Vera Staff",
      handle: "vera",
      teamLabel: "Dirt",
      attendanceState: "scheduled",
      checkedInAt: null,
    },
    {
      assignmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3",
      staffId: "33333333-3333-4333-8333-333333333335",
      displayName: "Sam Shiftlead",
      handle: "sam",
      teamLabel: "Dirt",
      attendanceState: "checked_in",
      checkedInAt: "2027-07-04T16:03:00.000Z",
    },
  ],
  unscheduledCandidates: [
    {
      staffId: "33333333-3333-4333-8333-333333333336",
      displayName: "Ari Ranger",
      handle: "ari",
      teamLabel: "Dirt",
    },
  ],
};

export function attendanceStateLabel(state: ShiftAttendanceState): string {
  switch (state) {
    case "checked_in":
      return "Checked in";
    case "checked_out":
      return "Checked out";
    case "no_show":
      return "No-show";
    case "excused":
      return "Excused";
    case "corrected":
      return "Corrected";
    case "scheduled":
      return "Scheduled";
  }
}

export function checkedInRoster(
  board: CurrentShiftBoard,
): readonly ShiftBoardRosterMember[] {
  return board.roster.filter(
    (member) => member.attendanceState === "checked_in",
  );
}

export function rosterSummary(board: CurrentShiftBoard): {
  readonly rosterCount: number;
  readonly checkedInCount: number;
} {
  return {
    rosterCount: board.roster.length,
    checkedInCount: checkedInRoster(board).length,
  };
}

export function eligibleUnscheduledCandidates(
  board: CurrentShiftBoard,
): readonly UnscheduledStaffCandidate[] {
  const rosterStaffIds = new Set(board.roster.map((member) => member.staffId));

  return board.unscheduledCandidates.filter(
    (candidate) => !rosterStaffIds.has(candidate.staffId),
  );
}

export function addUnscheduledRosterMember(
  board: CurrentShiftBoard,
  staffId: string,
  assignmentId: string,
): CurrentShiftBoard {
  const candidate = eligibleUnscheduledCandidates(board).find(
    (item) => item.staffId === staffId,
  );

  if (candidate === undefined) {
    throw new Error("Eligible unscheduled staff member is not available.");
  }

  return {
    ...board,
    roster: [
      ...board.roster,
      {
        assignmentId,
        staffId: candidate.staffId,
        displayName: candidate.displayName,
        handle: candidate.handle,
        teamLabel: candidate.teamLabel,
        attendanceState: "scheduled",
        checkedInAt: null,
      },
    ],
  };
}
