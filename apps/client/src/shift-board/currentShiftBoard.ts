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

export const EQUIPMENT_STATES = [
  "available",
  "checked_out",
  "returned",
  "missing",
  "damaged",
] as const;

export type EquipmentState = (typeof EQUIPMENT_STATES)[number];

export type EquipmentReturnCondition = Extract<
  EquipmentState,
  "returned" | "missing" | "damaged"
>;

export interface ShiftBoardRosterMember {
  readonly assignmentId: string;
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
  readonly attendanceState: ShiftAttendanceState;
  readonly checkedInAt: string | null;
  readonly currentDeploymentId: string | null;
}

export interface UnscheduledStaffCandidate {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
}

export interface DeploymentOption {
  readonly deploymentId: string;
  readonly name: string;
  readonly description: string | null;
  readonly locationDetails: string | null;
}

export interface EquipmentItem {
  readonly equipmentItemId: string;
  readonly name: string;
  readonly assetTag: string | null;
  readonly status: EquipmentState;
}

export interface EquipmentCheckout {
  readonly checkoutId: string;
  readonly equipmentItemId: string;
  readonly staffId: string;
  readonly checkedOutAt: string;
  readonly returnedAt: string | null;
  readonly returnCondition: EquipmentReturnCondition | null;
}

export interface CheckedOutEquipment {
  readonly checkoutId: string;
  readonly equipmentItemId: string;
  readonly itemName: string;
  readonly assetTag: string | null;
  readonly staffId: string;
  readonly staffName: string;
  readonly checkedOutAt: string;
  readonly status: EquipmentState;
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
  readonly deploymentOptions: readonly DeploymentOption[];
  readonly equipmentItems: readonly EquipmentItem[];
  readonly equipmentCheckouts: readonly EquipmentCheckout[];
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
      currentDeploymentId: "deployment-gate-1",
    },
    {
      assignmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2",
      staffId: "33333333-3333-4333-8333-333333333334",
      displayName: "Vera Staff",
      handle: "vera",
      teamLabel: "Dirt",
      attendanceState: "scheduled",
      checkedInAt: null,
      currentDeploymentId: null,
    },
    {
      assignmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3",
      staffId: "33333333-3333-4333-8333-333333333335",
      displayName: "Sam Shiftlead",
      handle: "sam",
      teamLabel: "Dirt",
      attendanceState: "checked_in",
      checkedInAt: "2027-07-04T16:03:00.000Z",
      currentDeploymentId: "deployment-perimeter-north",
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
  deploymentOptions: [
    {
      deploymentId: "deployment-gate-1",
      name: "Gate 1",
      description: "Entry checkpoint",
      locationDetails: "North entry checkpoint",
    },
    {
      deploymentId: "deployment-perimeter-north",
      name: "Perimeter North",
      description: "Roving perimeter watch",
      locationDetails: "North fence line",
    },
    {
      deploymentId: "deployment-hq-runner",
      name: "HQ Runner",
      description: "Radio and supply runner",
      locationDetails: "Ranger HQ",
    },
  ],
  equipmentItems: [
    {
      equipmentItemId: "equipment-radio-12",
      name: "Radio 12",
      assetTag: "RDO-12",
      status: "checked_out",
    },
    {
      equipmentItemId: "equipment-safety-vest",
      name: "Safety Vest",
      assetTag: "VEST-04",
      status: "available",
    },
    {
      equipmentItemId: "equipment-shift-flag",
      name: "Shift Flag",
      assetTag: "FLAG-01",
      status: "returned",
    },
  ],
  equipmentCheckouts: [
    {
      checkoutId: "equipment-checkout-radio-12",
      equipmentItemId: "equipment-radio-12",
      staffId: LOCAL_FIELD_FIXTURE.staffId,
      checkedOutAt: "2027-07-04T16:05:00.000Z",
      returnedAt: null,
      returnCondition: null,
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

export function deploymentOptionFor(
  board: CurrentShiftBoard,
  deploymentId: string | null,
): DeploymentOption | null {
  if (deploymentId === null) {
    return null;
  }

  return (
    board.deploymentOptions.find(
      (deployment) => deployment.deploymentId === deploymentId,
    ) ?? null
  );
}

export function deploymentLabel(
  board: CurrentShiftBoard,
  deploymentId: string | null,
): string {
  return deploymentOptionFor(board, deploymentId)?.name ?? "Unassigned";
}

export function equipmentStateLabel(state: EquipmentState): string {
  switch (state) {
    case "available":
      return "Available";
    case "checked_out":
      return "Checked out";
    case "returned":
      return "Returned";
    case "missing":
      return "Missing";
    case "damaged":
      return "Damaged";
  }
}

export function equipmentItemLabel(item: EquipmentItem): string {
  return item.assetTag === null ? item.name : `${item.name} (${item.assetTag})`;
}

export function equipmentSummary(board: CurrentShiftBoard): {
  readonly checkedOutCount: number;
} {
  return {
    checkedOutCount: checkedOutEquipment(board).length,
  };
}

export function checkoutReadyEquipment(
  board: CurrentShiftBoard,
): readonly EquipmentItem[] {
  const openEquipmentItemIds = new Set(
    board.equipmentCheckouts
      .filter((checkout) => checkout.returnedAt === null)
      .map((checkout) => checkout.equipmentItemId),
  );

  return board.equipmentItems.filter(
    (item) =>
      (item.status === "available" || item.status === "returned") &&
      !openEquipmentItemIds.has(item.equipmentItemId),
  );
}

export function checkedOutEquipment(
  board: CurrentShiftBoard,
): readonly CheckedOutEquipment[] {
  return board.equipmentCheckouts
    .filter((checkout) => checkout.returnedAt === null)
    .map((checkout) => {
      const item = board.equipmentItems.find(
        (equipment) => equipment.equipmentItemId === checkout.equipmentItemId,
      );
      const staff = board.roster.find(
        (member) => member.staffId === checkout.staffId,
      );

      if (item === undefined) {
        return null;
      }

      return {
        checkoutId: checkout.checkoutId,
        equipmentItemId: item.equipmentItemId,
        itemName: item.name,
        assetTag: item.assetTag,
        staffId: checkout.staffId,
        staffName: staff?.displayName ?? "Unknown staff",
        checkedOutAt: checkout.checkedOutAt,
        status: item.status,
      } satisfies CheckedOutEquipment;
    })
    .filter((item): item is CheckedOutEquipment => item !== null);
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
        currentDeploymentId: null,
      },
    ],
  };
}

export function checkoutEquipmentToStaff(
  board: CurrentShiftBoard,
  equipmentItemId: string,
  assignmentId: string,
  checkoutId: string,
  checkedOutAt: string,
): CurrentShiftBoard {
  const item = checkoutReadyEquipment(board).find(
    (equipment) => equipment.equipmentItemId === equipmentItemId,
  );

  if (item === undefined) {
    throw new Error("Equipment item is not available for checkout.");
  }

  const member = board.roster.find(
    (rosterMember) => rosterMember.assignmentId === assignmentId,
  );

  if (member === undefined) {
    throw new Error("Roster member is not available.");
  }

  return {
    ...board,
    equipmentItems: board.equipmentItems.map((equipment) =>
      equipment.equipmentItemId === item.equipmentItemId
        ? { ...equipment, status: "checked_out" }
        : equipment,
    ),
    equipmentCheckouts: [
      ...board.equipmentCheckouts,
      {
        checkoutId,
        equipmentItemId: item.equipmentItemId,
        staffId: member.staffId,
        checkedOutAt,
        returnedAt: null,
        returnCondition: null,
      },
    ],
  };
}

export function addEquipmentAndCheckoutToStaff(
  board: CurrentShiftBoard,
  item: {
    readonly equipmentItemId: string;
    readonly name: string;
    readonly assetTag: string | null;
  },
  assignmentId: string,
  checkoutId: string,
  checkedOutAt: string,
): CurrentShiftBoard {
  if (item.name.trim().length === 0) {
    throw new Error("Equipment name is required.");
  }

  if (
    board.equipmentItems.some(
      (equipment) => equipment.equipmentItemId === item.equipmentItemId,
    )
  ) {
    throw new Error("Equipment item already exists.");
  }

  const member = board.roster.find(
    (rosterItem) => rosterItem.assignmentId === assignmentId,
  );
  if (member === undefined) {
    throw new Error("Roster member is not available for equipment checkout.");
  }

  return {
    ...board,
    equipmentItems: [
      ...board.equipmentItems,
      {
        equipmentItemId: item.equipmentItemId,
        name: item.name.trim(),
        assetTag: item.assetTag?.trim() || null,
        status: "checked_out",
      },
    ],
    equipmentCheckouts: [
      ...board.equipmentCheckouts,
      {
        checkoutId,
        equipmentItemId: item.equipmentItemId,
        staffId: member.staffId,
        checkedOutAt,
        returnedAt: null,
        returnCondition: null,
      },
    ],
  };
}

export function returnEquipmentFromStaff(
  board: CurrentShiftBoard,
  checkoutId: string,
  returnCondition: EquipmentReturnCondition,
  returnedAt: string,
): CurrentShiftBoard {
  const checkout = board.equipmentCheckouts.find(
    (item) => item.checkoutId === checkoutId,
  );

  if (checkout === undefined || checkout.returnedAt !== null) {
    throw new Error("Checked-out equipment is not available for return.");
  }

  return {
    ...board,
    equipmentItems: board.equipmentItems.map((equipment) =>
      equipment.equipmentItemId === checkout.equipmentItemId
        ? { ...equipment, status: returnCondition }
        : equipment,
    ),
    equipmentCheckouts: board.equipmentCheckouts.map((item) =>
      item.checkoutId === checkout.checkoutId
        ? {
            ...item,
            returnedAt,
            returnCondition,
          }
        : item,
    ),
  };
}

export function assignCurrentDeployment(
  board: CurrentShiftBoard,
  assignmentId: string,
  deploymentId: string,
): CurrentShiftBoard {
  const deployment = deploymentOptionFor(board, deploymentId);

  if (deployment === null) {
    throw new Error("Deployment option is not available.");
  }

  let assignmentFound = false;

  const roster = board.roster.map((member) => {
    if (member.assignmentId !== assignmentId) {
      return member;
    }

    assignmentFound = true;

    return {
      ...member,
      currentDeploymentId: deployment.deploymentId,
    };
  });

  if (!assignmentFound) {
    throw new Error("Roster member is not available.");
  }

  return {
    ...board,
    roster,
  };
}
