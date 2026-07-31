import { shallowRef } from "vue";

import {
  FIXTURE_DPW_BIKES_TEAM_ID,
  FIXTURE_DPW_DEPARTMENT_ID,
  FIXTURE_GATE_CREDENTIALS_TEAM_ID,
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  FIXTURE_RANGERS_DIRT_TEAM_ID,
  fixtureDepartmentById,
} from "@/department-teams/fixtureDepartmentAccess";
import {
  canAccessDepartmentAdmin,
  canAdministerDepartment,
  listDepartmentTeams,
  listTeamLeadTeams,
  type DepartmentSelfAdminSession,
  type DepartmentTeam,
} from "@/department-teams/fixtureDepartmentSession";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";

/**
 * Product shift administration model (M11.17; SHIFT-001 through SHIFT-010).
 *
 * Documented eligibility and time-window rules mirrored from the server:
 * - shifts belong to the department's event with exactly one eligible team;
 * - scheduled end must be after start; signup close after signup open;
 * - capacity is null or >= 1 and never below current assignments;
 * - once a shift has started its schedule and eligible team are locked and it
 *   can no longer be cancelled or restored;
 * - cancelled shifts must be restored before editing.
 */
export interface ProductShift {
  readonly id: string;
  readonly departmentId: string;
  readonly eventId: string;
  readonly eligibleTeamId: string;
  readonly title: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly capacity: number | null;
  readonly activeAssignmentCount: number;
  readonly signupOpensAt: string | null;
  readonly signupClosesAt: string | null;
  readonly scheduleLockAt: string | null;
  readonly requiredTrainingIds: readonly string[];
  readonly requiredWaiverIds: readonly string[];
  readonly cancelledAt: string | null;
  readonly updatedAt: string;
}

export interface ShiftDraft {
  eligibleTeamId: string;
  title: string;
  startsAt: string;
  endsAt: string;
  capacity: number | null;
  signupOpensAt: string | null;
  signupClosesAt: string | null;
  scheduleLockAt: string | null;
  requiredTrainingIds: string[];
  requiredWaiverIds: string[];
}

export interface RequirementOption {
  readonly id: string;
  readonly name: string;
}

const FIXTURE_NOW_OFFSET_HOURS = 24 * 7;

function hoursFromNow(hours: number): string {
  return new Date(Date.now() + hours * 3_600_000).toISOString();
}

export const FIXTURE_TRAINING_OPTIONS: Record<string, RequirementOption[]> = {
  [FIXTURE_RANGERS_DEPARTMENT_ID]: [
    { id: "88888888-8888-4888-8888-888888888801", name: "Ranger Basic Training" },
    { id: "88888888-8888-4888-8888-888888888802", name: "Radio Etiquette" },
  ],
  [FIXTURE_GATE_DEPARTMENT_ID]: [
    { id: "88888888-8888-4888-8888-888888888803", name: "Gate Orientation" },
  ],
  [FIXTURE_DPW_DEPARTMENT_ID]: [
    { id: "88888888-8888-4888-8888-888888888804", name: "Heavy Equipment Safety" },
  ],
};

export const FIXTURE_WAIVER_OPTIONS: Record<string, RequirementOption[]> = {
  [FIXTURE_RANGERS_DEPARTMENT_ID]: [
    { id: "99999999-9999-4999-8999-999999999901", name: "Event Liability Waiver" },
  ],
  [FIXTURE_GATE_DEPARTMENT_ID]: [
    { id: "99999999-9999-4999-8999-999999999901", name: "Event Liability Waiver" },
  ],
  [FIXTURE_DPW_DEPARTMENT_ID]: [
    { id: "99999999-9999-4999-8999-999999999901", name: "Event Liability Waiver" },
    { id: "99999999-9999-4999-8999-999999999902", name: "Power Tools Waiver" },
  ],
};

const INITIAL_SHIFTS: ProductShift[] = [
  {
    id: "aaaaaaa1-0000-4000-8000-000000000001",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    eligibleTeamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
    title: "Dirt Patrol (Day)",
    startsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS),
    endsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS + 8),
    capacity: 6,
    activeAssignmentCount: 2,
    signupOpensAt: hoursFromNow(2),
    signupClosesAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS - 12),
    scheduleLockAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS - 6),
    requiredTrainingIds: ["88888888-8888-4888-8888-888888888801"],
    requiredWaiverIds: ["99999999-9999-4999-8999-999999999901"],
    cancelledAt: null,
    updatedAt: hoursFromNow(-24),
  },
  {
    id: "aaaaaaa1-0000-4000-8000-000000000002",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    eligibleTeamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
    title: "Dirt Patrol (Started)",
    startsAt: hoursFromNow(-2),
    endsAt: hoursFromNow(6),
    capacity: 4,
    activeAssignmentCount: 4,
    signupOpensAt: null,
    signupClosesAt: null,
    scheduleLockAt: null,
    requiredTrainingIds: [],
    requiredWaiverIds: [],
    cancelledAt: null,
    updatedAt: hoursFromNow(-48),
  },
  {
    id: "aaaaaaa1-0000-4000-8000-000000000003",
    departmentId: FIXTURE_RANGERS_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    eligibleTeamId: FIXTURE_RANGERS_DIRT_TEAM_ID,
    title: "Dirt Patrol (Cancelled)",
    startsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS + 24),
    endsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS + 32),
    capacity: null,
    activeAssignmentCount: 0,
    signupOpensAt: null,
    signupClosesAt: null,
    scheduleLockAt: null,
    requiredTrainingIds: [],
    requiredWaiverIds: [],
    cancelledAt: hoursFromNow(-12),
    updatedAt: hoursFromNow(-12),
  },
  {
    id: "aaaaaaa1-0000-4000-8000-000000000004",
    departmentId: FIXTURE_GATE_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    eligibleTeamId: FIXTURE_GATE_CREDENTIALS_TEAM_ID,
    title: "Credential Check",
    startsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS),
    endsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS + 6),
    capacity: 3,
    activeAssignmentCount: 1,
    signupOpensAt: null,
    signupClosesAt: null,
    scheduleLockAt: null,
    requiredTrainingIds: ["88888888-8888-4888-8888-888888888803"],
    requiredWaiverIds: [],
    cancelledAt: null,
    updatedAt: hoursFromNow(-24),
  },
  {
    id: "aaaaaaa1-0000-4000-8000-000000000005",
    departmentId: FIXTURE_DPW_DEPARTMENT_ID,
    eventId: LOCAL_FIELD_FIXTURE.eventId,
    eligibleTeamId: FIXTURE_DPW_BIKES_TEAM_ID,
    title: "Bike Repair Bench",
    startsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS + 8),
    endsAt: hoursFromNow(FIXTURE_NOW_OFFSET_HOURS + 14),
    capacity: 2,
    activeAssignmentCount: 0,
    signupOpensAt: null,
    signupClosesAt: null,
    scheduleLockAt: null,
    requiredTrainingIds: [],
    requiredWaiverIds: ["99999999-9999-4999-8999-999999999902"],
    cancelledAt: null,
    updatedAt: hoursFromNow(-24),
  },
];

const shifts = shallowRef<ProductShift[]>(
  INITIAL_SHIFTS.map((shift) => ({ ...shift })),
);

export type ShiftStatusFilter = "all" | "active" | "cancelled";

export function canViewShiftAdmin(
  session: DepartmentSelfAdminSession | null,
): boolean {
  return canAccessDepartmentAdmin(session);
}

/**
 * Staff who are not department or team leads still see the shifts their teams
 * are eligible for, read-only, through the Staff menu.
 */
export function canViewMemberShifts(
  session: DepartmentSelfAdminSession | null,
): boolean {
  return session !== null && session.memberTeamIds.length > 0;
}

/**
 * Display names for the teams the signed-in member belongs to, so the
 * read-only member list can label shifts without department admin reads.
 */
export function memberTeamLabels(
  session: DepartmentSelfAdminSession | null,
): Map<string, string> {
  if (session === null) {
    return new Map();
  }

  const department = fixtureDepartmentById(session.departmentId);

  return new Map(
    (department?.teams ?? []).map((team) => [team.teamId, team.teamLabel]),
  );
}

/**
 * Active shifts a plain member is eligible for, by team membership (SHIFT-004).
 */
export function listMemberShifts(
  session: DepartmentSelfAdminSession | null,
): ProductShift[] {
  if (session === null || !canViewMemberShifts(session)) {
    return [];
  }

  const memberTeamIds = new Set(session.memberTeamIds);

  return shifts.value
    .filter(
      (shift) =>
        shift.departmentId === session.departmentId &&
        shift.cancelledAt === null &&
        memberTeamIds.has(shift.eligibleTeamId),
    )
    .slice()
    .sort(
      (left, right) =>
        new Date(left.startsAt).getTime() - new Date(right.startsAt).getTime(),
    );
}

export function canManageShiftsForTeam(
  session: DepartmentSelfAdminSession | null,
  teamId: string,
): boolean {
  if (session === null) {
    return false;
  }

  if (canAdministerDepartment(session)) {
    return true;
  }

  return session.teamLeadTeamIds.includes(teamId);
}

/**
 * Active teams the current session may schedule shifts for.
 */
export function listSchedulableTeams(
  session: DepartmentSelfAdminSession | null,
): DepartmentTeam[] {
  if (session === null || !canViewShiftAdmin(session)) {
    return [];
  }

  if (canAdministerDepartment(session)) {
    return listDepartmentTeams(session, "active");
  }

  return listTeamLeadTeams(session).filter((team) => team.archivedAt === null);
}

export function listDepartmentShifts(
  session: DepartmentSelfAdminSession | null,
  status: ShiftStatusFilter = "all",
): ProductShift[] {
  if (session === null || !canViewShiftAdmin(session)) {
    return [];
  }

  const manageableTeamIds = canAdministerDepartment(session)
    ? null
    : new Set(session.teamLeadTeamIds);

  return shifts.value
    .filter((shift) => shift.departmentId === session.departmentId)
    .filter(
      (shift) =>
        manageableTeamIds === null || manageableTeamIds.has(shift.eligibleTeamId),
    )
    .filter((shift) => {
      if (status === "active") {
        return shift.cancelledAt === null;
      }

      if (status === "cancelled") {
        return shift.cancelledAt !== null;
      }

      return true;
    })
    .slice()
    .sort(
      (left, right) =>
        new Date(left.startsAt).getTime() - new Date(right.startsAt).getTime(),
    );
}

export function getShift(
  session: DepartmentSelfAdminSession | null,
  shiftId: string,
): ProductShift | null {
  if (session === null || !canViewShiftAdmin(session)) {
    return null;
  }

  const shift =
    shifts.value.find(
      (candidate) =>
        candidate.id === shiftId &&
        candidate.departmentId === session.departmentId,
    ) ?? null;

  if (shift === null) {
    return null;
  }

  if (!canManageShiftsForTeam(session, shift.eligibleTeamId)) {
    return null;
  }

  return shift;
}

export function shiftHasStarted(shift: ProductShift): boolean {
  return new Date(shift.startsAt).getTime() <= Date.now();
}

export function listTrainingOptions(
  session: DepartmentSelfAdminSession | null,
): RequirementOption[] {
  if (session === null) {
    return [];
  }

  return FIXTURE_TRAINING_OPTIONS[session.departmentId] ?? [];
}

export function listWaiverOptions(
  session: DepartmentSelfAdminSession | null,
): RequirementOption[] {
  if (session === null) {
    return [];
  }

  return FIXTURE_WAIVER_OPTIONS[session.departmentId] ?? [];
}

export function createShift(
  session: DepartmentSelfAdminSession | null,
  draft: ShiftDraft,
): ProductShift {
  assertCanManageTeam(session, draft.eligibleTeamId);
  const normalized = normalizeDraft(session, draft);

  const shift: ProductShift = {
    id: crypto.randomUUID(),
    departmentId: session.departmentId,
    eventId: session.eventId,
    activeAssignmentCount: 0,
    cancelledAt: null,
    updatedAt: new Date().toISOString(),
    ...normalized,
  };

  shifts.value = [...shifts.value, shift];

  return shift;
}

export function updateShift(
  session: DepartmentSelfAdminSession | null,
  shiftId: string,
  draft: ShiftDraft,
): ProductShift {
  assertCanManageTeam(session, draft.eligibleTeamId);
  const existing = requireManagedShift(session, shiftId);

  if (existing.cancelledAt !== null) {
    throw new Error("Cancelled shifts must be restored before editing.");
  }

  if (shiftHasStarted(existing)) {
    if (
      existing.startsAt !== draft.startsAt ||
      existing.endsAt !== draft.endsAt
    ) {
      throw new Error("Scheduled times are locked once the shift has started.");
    }

    if (existing.eligibleTeamId !== draft.eligibleTeamId) {
      throw new Error("The eligible team is locked once the shift has started.");
    }
  }

  const normalized = normalizeDraft(session, draft);

  if (
    normalized.capacity !== null &&
    normalized.capacity < existing.activeAssignmentCount
  ) {
    throw new Error(
      "Capacity cannot be set below the current number of assigned staff.",
    );
  }

  const updated: ProductShift = {
    ...existing,
    ...normalized,
    updatedAt: new Date().toISOString(),
  };

  shifts.value = shifts.value.map((shift) =>
    shift.id === shiftId ? updated : shift,
  );

  return updated;
}

export function cancelShift(
  session: DepartmentSelfAdminSession | null,
  shiftId: string,
): ProductShift {
  const existing = requireManagedShift(session, shiftId);

  if (existing.cancelledAt !== null) {
    throw new Error("Shift is already cancelled.");
  }

  if (shiftHasStarted(existing)) {
    throw new Error("Shifts cannot be cancelled once they have started.");
  }

  const cancelled: ProductShift = {
    ...existing,
    cancelledAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  };

  shifts.value = shifts.value.map((shift) =>
    shift.id === shiftId ? cancelled : shift,
  );

  return cancelled;
}

export function restoreShift(
  session: DepartmentSelfAdminSession | null,
  shiftId: string,
): ProductShift {
  const existing = requireManagedShift(session, shiftId);

  if (existing.cancelledAt === null) {
    throw new Error("Shift is not cancelled.");
  }

  if (shiftHasStarted(existing)) {
    throw new Error("Shifts cannot be restored after their scheduled start.");
  }

  const restored: ProductShift = {
    ...existing,
    cancelledAt: null,
    updatedAt: new Date().toISOString(),
  };

  shifts.value = shifts.value.map((shift) =>
    shift.id === shiftId ? restored : shift,
  );

  return restored;
}

export function resetShiftAdminFixtures(): void {
  shifts.value = INITIAL_SHIFTS.map((shift) => ({ ...shift }));
}

type NormalizedDraft = Pick<
  ProductShift,
  | "eligibleTeamId"
  | "title"
  | "startsAt"
  | "endsAt"
  | "capacity"
  | "signupOpensAt"
  | "signupClosesAt"
  | "scheduleLockAt"
  | "requiredTrainingIds"
  | "requiredWaiverIds"
>;

function normalizeDraft(
  session: DepartmentSelfAdminSession,
  draft: ShiftDraft,
): NormalizedDraft {
  const title = draft.title.trim();

  if (title === "") {
    throw new Error("Shifts require a displayed title/function.");
  }

  if (draft.startsAt === "" || draft.endsAt === "") {
    throw new Error("Shifts require scheduled start and end times.");
  }

  const startsAt = new Date(draft.startsAt);
  const endsAt = new Date(draft.endsAt);

  if (endsAt.getTime() <= startsAt.getTime()) {
    throw new Error("Shift end must be after shift start.");
  }

  if (
    draft.signupOpensAt !== null &&
    draft.signupClosesAt !== null &&
    new Date(draft.signupClosesAt).getTime() <=
      new Date(draft.signupOpensAt).getTime()
  ) {
    throw new Error("Signup close must be after signup open.");
  }

  if (draft.capacity !== null && draft.capacity < 1) {
    throw new Error("Capacity must be at least 1 when set.");
  }

  const validTrainingIds = new Set(
    listTrainingOptions(session).map((option) => option.id),
  );
  const validWaiverIds = new Set(
    listWaiverOptions(session).map((option) => option.id),
  );

  return {
    eligibleTeamId: draft.eligibleTeamId,
    title,
    startsAt: startsAt.toISOString(),
    endsAt: endsAt.toISOString(),
    capacity: draft.capacity,
    signupOpensAt: draft.signupOpensAt,
    signupClosesAt: draft.signupClosesAt,
    scheduleLockAt: draft.scheduleLockAt,
    requiredTrainingIds: draft.requiredTrainingIds.filter((id) =>
      validTrainingIds.has(id),
    ),
    requiredWaiverIds: draft.requiredWaiverIds.filter((id) =>
      validWaiverIds.has(id),
    ),
  };
}

function requireManagedShift(
  session: DepartmentSelfAdminSession | null,
  shiftId: string,
): ProductShift {
  if (session === null || !canViewShiftAdmin(session)) {
    throw new Error("You do not have permission to manage shifts.");
  }

  const shift = getShift(session, shiftId);

  if (shift === null) {
    throw new Error("Shift not found.");
  }

  return shift;
}

function assertCanManageTeam(
  session: DepartmentSelfAdminSession | null,
  teamId: string,
): asserts session is DepartmentSelfAdminSession {
  if (session === null || !canViewShiftAdmin(session)) {
    throw new Error("You do not have permission to manage shifts.");
  }

  if (!canManageShiftsForTeam(session, teamId)) {
    throw new Error(
      "You are not authorized to manage shifts for the selected team.",
    );
  }
}
