// The department shift surfaces' data layer (M16.18; CLIENT-023, SHIFT-001
// through SHIFT-010).
//
// Until this task this module was the schedule. Five shifts were compiled into
// the client, a `shallowRef` held them, and the rules `ShiftAdminService`
// enforces on the node were written out a second time in the browser: end after
// start, signup close after signup open, capacity never below the assignments
// already made, the schedule and eligible team locked once a shift has started,
// and cancel/restore refused past that point. Authority was read off a fixture
// department's role flags. Nothing reached a node, so a shift it accepted was
// nobody's shift and a lock it enforced was not the node's lock — and each rule
// kept here could only drift from the one that actually governs.
//
// This module is now a translation of `/api/departments/{id}/shifts` and the
// four shift commands. Four choices in it are deliberate:
//
//  1. **One read per surface.** The index answers with the department, the
//     caller's authority over it, the teams a shift may be assigned to, the
//     trainings and waivers a shift may require, and every shift the caller may
//     see. The list page and the featureset embedded in Planning each render
//     off that one response.
//  2. **Authority comes from the response.** `access.can_manage` is the node's
//     answer for the surface and `canManage` on each shift is its answer for
//     that row, which is the same answer the commands enforce. The old
//     `canViewShiftAdmin` / `canManageShiftsForTeam` predicates over fixture
//     roles are gone; all they could do was disagree with the server
//     (CLIENT-006).
//  3. **No cache and no client-side rules.** Nothing is held between calls and
//     every write is followed by a re-read. `hasStarted` is the node's reading
//     of the clock rather than a comparison this module makes, and the
//     schedule, capacity, signup-window, and lock rules are the server's to
//     decide, with its refusals shown as it worded them.
//  4. **The status filter is the endpoint's.** `?status=` narrows the read
//     rather than the rendered list, so what the table shows is what the node
//     answered for that filter.
//
// These are connected-only surfaces. Shift administration is not in the closed
// set of offline-writable work (data/API 7.2), so a request made with no node
// reachable fails and says so rather than queueing.

import { meridianJson } from "@/api/meridianApi";

/** A team a shift may be assigned to, as the node offers them to this caller. */
export interface ShiftTeamOption {
  readonly id: string;
  readonly name: string;
  readonly isDefault: boolean;
}

/** A training or waiver a shift may require (SHIFT-005, SHIFT-006). */
export interface ShiftRequirementOption {
  readonly id: string;
  readonly name: string;
}

export interface ProductShift {
  readonly id: string;
  readonly departmentId: string;
  readonly eventId: string;
  readonly eventName: string | null;
  readonly eligibleTeamId: string;
  /** The node's name for the eligible team, so a row never needs a team lookup. */
  readonly eligibleTeamName: string | null;
  readonly title: string;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly capacity: number | null;
  readonly activeAssignmentCount: number;
  readonly signupOpensAt: string | null;
  readonly signupClosesAt: string | null;
  readonly scheduleLockAt: string | null;
  readonly requiredTrainingIds: readonly string[];
  readonly requiredWaiverIds: readonly string[];
  readonly cancelledAt: string | null;
  /**
   * Whether the shift has begun, as the node read its own clock. What it locks
   * — schedule, eligible team, cancellation — is enforced there; carrying the
   * answer rather than computing it keeps one clock behind the lock.
   */
  readonly hasStarted: boolean;
  /** Whether this caller may edit, cancel, or restore this shift. */
  readonly canManage: boolean;
  readonly updatedAt: string | null;
}

/**
 * What the caller may do on the surface as a whole, as the node decided it.
 *
 * A department with no shifts yet still has to decide whether the page offers to
 * create one, which no individual shift's `canManage` could answer.
 */
export interface ShiftWorkspaceAccess {
  readonly canAdminister: boolean;
  readonly canManage: boolean;
}

/** The whole `department.shifts` surface in one response. */
export interface ShiftWorkspace {
  readonly departmentId: string;
  readonly departmentName: string;
  readonly access: ShiftWorkspaceAccess;
  readonly teams: readonly ShiftTeamOption[];
  readonly trainingOptions: readonly ShiftRequirementOption[];
  readonly waiverOptions: readonly ShiftRequirementOption[];
  readonly shifts: readonly ProductShift[];
}

/** The shift form, as edited. Timestamps are ISO strings or null. */
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

export type ShiftStatusFilter = "all" | "active" | "cancelled";

interface ShiftPayload {
  readonly id: string;
  readonly event_id: string;
  readonly event_name?: string | null;
  readonly department_id: string;
  readonly eligible_team_id: string;
  readonly eligible_team_name?: string | null;
  readonly title: string;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly capacity: number | null;
  readonly active_assignment_count?: number;
  readonly signup_opens_at: string | null;
  readonly signup_closes_at: string | null;
  readonly schedule_lock_at: string | null;
  readonly cancelled_at: string | null;
  readonly has_started?: boolean;
  readonly can_manage?: boolean;
  readonly required_training_ids?: string[];
  readonly required_waiver_ids?: string[];
  readonly updated_at?: string | null;
}

interface ShiftIndexPayload {
  readonly department_id?: string;
  readonly department?: { readonly id: string; readonly name: string };
  readonly access?: {
    readonly can_administer?: boolean;
    readonly can_manage?: boolean;
  };
  readonly teams?: {
    readonly id: string;
    readonly name: string;
    readonly is_default?: boolean;
  }[];
  readonly training_options?: ShiftRequirementOption[];
  readonly waiver_options?: ShiftRequirementOption[];
  readonly shifts?: ShiftPayload[];
}

function toShift(payload: ShiftPayload): ProductShift {
  return {
    id: payload.id,
    departmentId: payload.department_id,
    eventId: payload.event_id,
    eventName: payload.event_name ?? null,
    eligibleTeamId: payload.eligible_team_id,
    eligibleTeamName: payload.eligible_team_name ?? null,
    title: payload.title,
    startsAt: payload.starts_at,
    endsAt: payload.ends_at,
    capacity: payload.capacity,
    activeAssignmentCount: payload.active_assignment_count ?? 0,
    signupOpensAt: payload.signup_opens_at,
    signupClosesAt: payload.signup_closes_at,
    scheduleLockAt: payload.schedule_lock_at,
    requiredTrainingIds: payload.required_training_ids ?? [],
    requiredWaiverIds: payload.required_waiver_ids ?? [],
    cancelledAt: payload.cancelled_at,
    hasStarted: payload.has_started ?? false,
    canManage: payload.can_manage ?? false,
    updatedAt: payload.updated_at ?? null,
  };
}

/**
 * The submitted form, trimmed.
 *
 * The server trims too, so this changes nothing it stores. It changes what a
 * whitespace-only title does: sent as typed it passes `required` and comes back
 * as a domain refusal, which reads oddly next to a field that visibly has
 * something in it.
 */
function toAttributes(draft: ShiftDraft): Record<string, unknown> {
  return {
    eligible_team_id: draft.eligibleTeamId,
    title: draft.title.trim(),
    starts_at: draft.startsAt,
    ends_at: draft.endsAt,
    capacity:
      typeof draft.capacity === "number" && Number.isFinite(draft.capacity)
        ? draft.capacity
        : null,
    signup_opens_at: draft.signupOpensAt,
    signup_closes_at: draft.signupClosesAt,
    schedule_lock_at: draft.scheduleLockAt,
    required_training_ids: [...draft.requiredTrainingIds],
    required_waiver_ids: [...draft.requiredWaiverIds],
  };
}

/**
 * Read the whole shift surface for one department.
 *
 * The status filter goes to the node rather than narrowing what came back: the
 * list is the answer to the question that was asked, not a view of a wider one.
 * A member's read ignores it and answers with the active shifts their teams are
 * eligible for, which is the node's decision and not a case handled here.
 */
export async function getDepartmentShifts(
  departmentId: string,
  status: ShiftStatusFilter = "all",
): Promise<ShiftWorkspace> {
  const query = status === "all" ? "" : `?status=${status}`;
  const result = await meridianJson<ShiftIndexPayload>(
    `/api/departments/${departmentId}/shifts${query}`,
  );

  return {
    departmentId: result.department?.id ?? result.department_id ?? departmentId,
    departmentName: result.department?.name ?? "",
    access: {
      canAdminister: result.access?.can_administer ?? false,
      canManage: result.access?.can_manage ?? false,
    },
    teams: (result.teams ?? []).map((team) => ({
      id: team.id,
      name: team.name,
      isDefault: team.is_default ?? false,
    })),
    trainingOptions: result.training_options ?? [],
    waiverOptions: result.waiver_options ?? [],
    shifts: (result.shifts ?? []).map(toShift),
  };
}

/**
 * Read one shift for the edit form.
 *
 * A shift in another department, or one whose eligible team this caller does not
 * manage, is the node's refusal rather than a row missing from a list, so the
 * form can say what happened instead of rendering empty.
 */
export async function getShift(
  departmentId: string,
  shiftId: string,
): Promise<ProductShift> {
  return toShift(
    await meridianJson<ShiftPayload>(
      `/api/departments/${departmentId}/shifts/${shiftId}`,
    ),
  );
}

/**
 * Create a shift in one department and event, and answer with its id.
 *
 * The event comes from the client's event context, and whether it belongs to the
 * department's organization is the node's check (SHIFT-001). Nothing else about
 * the created shift is kept: the form navigates to the edit screen, which reads
 * it back.
 */
export async function createShift(
  departmentId: string,
  eventId: string,
  draft: ShiftDraft,
): Promise<string> {
  const created = await meridianJson<{ id: string }>(
    "/api/commands/create-shift",
    {
      method: "POST",
      body: JSON.stringify({
        department_id: departmentId,
        event_id: eventId,
        ...toAttributes(draft),
      }),
    },
  );

  return created.id;
}

export async function updateShift(
  shiftId: string,
  draft: ShiftDraft,
): Promise<void> {
  await meridianJson("/api/commands/update-shift", {
    method: "POST",
    body: JSON.stringify({ shift_id: shiftId, ...toAttributes(draft) }),
  });
}

export async function cancelShift(shiftId: string): Promise<void> {
  await meridianJson("/api/commands/cancel-shift", {
    method: "POST",
    body: JSON.stringify({ shift_id: shiftId }),
  });
}

export async function restoreShift(shiftId: string): Promise<void> {
  await meridianJson("/api/commands/restore-shift", {
    method: "POST",
    body: JSON.stringify({ shift_id: shiftId }),
  });
}

/**
 * Where a shift stands, from the two facts the node reports about it.
 *
 * Cancellation is stated first because a cancelled shift whose start has passed
 * is still cancelled: nobody is expected at it.
 */
export function shiftStatusLabel(shift: ProductShift): string {
  if (shift.cancelledAt !== null) {
    return "Cancelled";
  }

  return shift.hasStarted ? "Started" : "Scheduled";
}

export function shiftTeamLabel(shift: ProductShift): string {
  return shift.eligibleTeamName ?? "Unknown team";
}

export function shiftWindowLabel(shift: ProductShift): string {
  if (shift.startsAt === null || shift.endsAt === null) {
    return "Not scheduled";
  }

  return `${new Date(shift.startsAt).toLocaleString()} - ${new Date(
    shift.endsAt,
  ).toLocaleString()}`;
}

export function shiftCapacityLabel(shift: ProductShift): string {
  return shift.capacity === null
    ? `${shift.activeAssignmentCount} assigned / no cap`
    : `${shift.activeAssignmentCount} assigned / ${shift.capacity} cap`;
}

/**
 * The eligible-team options for one shift's form.
 *
 * The node offers the teams a shift may be assigned to, which are the active
 * ones this caller may schedule for. A shift already sitting on a team outside
 * that list — archived since, or assigned before the caller's authority narrowed
 * — still has to render with its own team named rather than with a blank select
 * that would silently move it on save.
 */
export function shiftTeamOptions(
  teams: readonly ShiftTeamOption[],
  shift: ProductShift | null,
): readonly ShiftTeamOption[] {
  if (shift === null || teams.some((team) => team.id === shift.eligibleTeamId)) {
    return teams;
  }

  return [
    ...teams,
    {
      id: shift.eligibleTeamId,
      name: shiftTeamLabel(shift),
      isDefault: false,
    },
  ];
}
