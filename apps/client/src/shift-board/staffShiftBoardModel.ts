// The staff shift board's data layer (M18.2; SHIFT-011 through SHIFT-015,
// SHIFT-018; requirements 3.12, 5.5; CLIENT-006, CLIENT-023).
//
// One read fills the surface and two commands change it. The read is
// `GET /api/events/{event}/shift-board`, which answers with every shift in the
// event that belongs to a department this staff member is a member of, each
// carrying the node's own verdict: whether they are on it, whether they may join
// it, and if not, the reason in the node's words.
//
// Nothing here decides eligibility. That is the point of the surface: SHIFT-018
// requires an unavailable shift to say *why* it is unavailable, and a client
// that worked out its own reasons would be a second rule set that can only drift
// from the one the command enforces (CLIENT-006). Every "you cannot take this"
// on screen is a sentence the node wrote, and every shift the board offers is
// one the command would accept.
//
// Both commands are connected-only. Data/API 7.2 closes the set of Alpha 1
// offline writes and signup is not in it: eligibility turns on trainings,
// waivers, department status, and a capacity count that changes under you, so a
// signup held on a device is a shift somebody thinks they hold.

import { meridianCachedJson } from "@/api/meridianApi";
import type { OfflineReadProjection } from "@/offline/offlineReadProjection";
import type { ReadFreshness } from "@/offline/readFreshness";
import { sendConnectedCommand } from "@/outbox/submitCommand";

/** An advisory that came back with a shift, or with a signup (SHIFT-014). */
export interface ShiftBoardWarning {
  readonly code: string;
  readonly message: string;
}

/** One row of the board, as the node decided it. */
export interface ShiftBoardEntry {
  readonly id: string;
  readonly departmentId: string;
  readonly departmentName: string | null;
  readonly eligibleTeamId: string;
  readonly eligibleTeamName: string | null;
  readonly title: string;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly capacity: number | null;
  /**
   * How many people are on it, as the node counted them. Null when nobody
   * counted: the offline copy of a shift carries the shift, not a tally that
   * changes every time somebody else signs up.
   */
  readonly activeAssignmentCount: number | null;
  readonly signupOpensAt: string | null;
  readonly signupClosesAt: string | null;
  readonly scheduleLockAt: string | null;
  readonly cancelledAt: string | null;
  /** What this shift asks of somebody before they may take it (SHIFT-005, SHIFT-006). */
  readonly requiredTrainingNames: readonly string[];
  readonly requiredWaiverNames: readonly string[];
  readonly signedUp: boolean;
  readonly assignmentStatus: string | null;
  readonly canSignUp: boolean;
  readonly canWithdraw: boolean;
  readonly scheduleLocked: boolean;
  /** Why this shift will not take them, in the node's words. Null when it will. */
  readonly unavailableReason: string | null;
  readonly unavailableReasonCode: string | null;
  readonly overlapWarnings: readonly ShiftBoardWarning[];
}

export interface ShiftBoard {
  readonly eventId: string;
  readonly eventName: string | null;
  readonly shifts: readonly ShiftBoardEntry[];
  /** Whether this board came from the node or from what the device stored. */
  readonly freshness: ReadFreshness;
}

interface ShiftBoardEntryPayload {
  readonly id: string;
  readonly department_id: string;
  readonly department_name?: string | null;
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
  readonly required_training_names?: string[];
  readonly required_waiver_names?: string[];
  readonly signed_up?: boolean;
  readonly assignment_status?: string | null;
  readonly can_sign_up?: boolean;
  readonly can_withdraw?: boolean;
  readonly schedule_locked?: boolean;
  readonly unavailable_reason?: string | null;
  readonly unavailable_reason_code?: string | null;
  readonly overlap_warnings?: ShiftBoardWarning[];
}

interface ShiftBoardPayload {
  readonly event?: {
    readonly id?: string;
    readonly name?: string | null;
  };
  readonly shifts?: ShiftBoardEntryPayload[];
}

function toEntry(payload: ShiftBoardEntryPayload): ShiftBoardEntry {
  return {
    id: payload.id,
    departmentId: payload.department_id,
    departmentName: payload.department_name ?? null,
    eligibleTeamId: payload.eligible_team_id,
    eligibleTeamName: payload.eligible_team_name ?? null,
    title: payload.title,
    startsAt: payload.starts_at,
    endsAt: payload.ends_at,
    capacity: payload.capacity,
    activeAssignmentCount: payload.active_assignment_count ?? null,
    signupOpensAt: payload.signup_opens_at,
    signupClosesAt: payload.signup_closes_at,
    scheduleLockAt: payload.schedule_lock_at,
    cancelledAt: payload.cancelled_at,
    requiredTrainingNames: payload.required_training_names ?? [],
    requiredWaiverNames: payload.required_waiver_names ?? [],
    signedUp: payload.signed_up ?? false,
    assignmentStatus: payload.assignment_status ?? null,
    canSignUp: payload.can_sign_up ?? false,
    canWithdraw: payload.can_withdraw ?? false,
    scheduleLocked: payload.schedule_locked ?? false,
    unavailableReason: payload.unavailable_reason ?? null,
    unavailableReasonCode: payload.unavailable_reason_code ?? null,
    overlapWarnings: payload.overlap_warnings ?? [],
  };
}

/** One shift as the offline read set carries it (technical spec 9.3). */
interface StoredShiftRow {
  readonly id: string;
  readonly event_id: string;
  readonly department_id: string;
  readonly eligible_team_id: string | null;
  readonly title: string;
  readonly department_name_snapshot: string | null;
  readonly team_name_snapshot: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly capacity: number | null;
  readonly signup_opens_at: string | null;
  readonly signup_closes_at: string | null;
  readonly schedule_lock_at: string | null;
  readonly cancelled_at: string | null;
}

interface StoredShiftAssignmentRow {
  readonly shift_id: string;
  readonly assignment_status: string | null;
}

interface StoredEventRow {
  readonly id: string;
  readonly name: string | null;
}

/**
 * The board this device can show with no node in reach (M18.50; CLIENT-021;
 * technical spec 9.3).
 *
 * It is narrower than the node's board and honestly so. Section 9.3 asks a
 * regular staff member's device to hold "their own shifts", and that is what the
 * set carries: the shifts they are assigned to, not every shift their
 * departments are running. So the offline board is the shifts they hold, each
 * marked signed up, and it does not offer any shift they might take — which is
 * the right answer twice over, because signup is connected-only (data/API 7.2)
 * and a board that offered a shift no command could accept would be inviting
 * somebody to believe they had taken it.
 *
 * Nothing here decides eligibility, for the reason at the head of this module.
 * `canSignUp` and `canWithdraw` are false because this device has no verdict, not
 * because it computed one, and no unavailability reason is invented to explain a
 * shift the reader is already on.
 *
 * The assignment count is left null rather than derived from the assignments this
 * device holds. It holds the caller's own assignment and no one else's, so
 * counting them would report every shift in the event as having exactly one
 * person on it.
 */
function storedShiftBoard(
  eventId: string,
): OfflineReadProjection<ShiftBoardPayload> {
  return (source) => {
    if (!source.carries("shifts")) {
      return null;
    }

    const assignments = new Map(
      source
        .section<StoredShiftAssignmentRow>("shift_assignments")
        .map((assignment) => [assignment.shift_id, assignment]),
    );

    const event =
      source
        .section<StoredEventRow>("events")
        .find((candidate) => candidate.id === eventId) ?? null;

    const shifts = source
      .section<StoredShiftRow>("shifts")
      .filter((shift) => shift.event_id === eventId)
      .map<ShiftBoardEntryPayload>((shift) => ({
        id: shift.id,
        department_id: shift.department_id,
        department_name: shift.department_name_snapshot,
        eligible_team_id: shift.eligible_team_id ?? "",
        eligible_team_name: shift.team_name_snapshot,
        title: shift.title,
        starts_at: shift.starts_at,
        ends_at: shift.ends_at,
        capacity: shift.capacity,
        signup_opens_at: shift.signup_opens_at,
        signup_closes_at: shift.signup_closes_at,
        schedule_lock_at: shift.schedule_lock_at,
        cancelled_at: shift.cancelled_at,
        signed_up: true,
        assignment_status: assignments.get(shift.id)?.assignment_status ?? null,
        can_sign_up: false,
        can_withdraw: false,
      }));

    return { data: { event: { id: eventId, name: event?.name ?? null }, shifts } };
  };
}

/**
 * Read the whole board for one event.
 *
 * Answered from what this device holds when the node cannot be reached, because
 * "their own shifts" is the first thing technical spec 9.3 asks a regular staff
 * member's device to hold. A volunteer standing where there is no signal still
 * needs to know when they are due and where; a board that answers "check the
 * connection to this node" is the failure that rule exists against.
 */
export async function getShiftBoard(
  eventId: string,
): Promise<ShiftBoard> {
  const { data, freshness } = await meridianCachedJson<ShiftBoardPayload>(
    `/api/events/${eventId}/shift-board`,
    { offline: storedShiftBoard(eventId) },
  );

  return {
    eventId: data.event?.id ?? eventId,
    eventName: data.event?.name ?? null,
    shifts: (data.shifts ?? []).map(toEntry),
    freshness,
  };
}

/**
 * Take a shift (SHIFT-011), and answer with whatever the node warned about.
 *
 * A warning is not a refusal. An overlap comes back *with* the acceptance
 * (SHIFT-014), so the caller reports it beside a signup that happened rather
 * than instead of one.
 */
export async function signUpForShift(
  eventId: string,
  shiftId: string,
): Promise<readonly string[]> {
  const result = await sendConnectedCommand({
    commandType: "sign-up-for-shift",
    idempotencyKey: `sign-up-${shiftId}`,
    payload: { shift_id: shiftId },
    eventId,
    detail: shiftId,
  });

  const warnings = (result as { warnings?: ShiftBoardWarning[] } | null)
    ?.warnings;

  return (warnings ?? []).map((warning) => warning.message);
}

/** Leave a shift before the cutoff (section 3.11). */
export async function withdrawFromShift(
  eventId: string,
  shiftId: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "withdraw-from-shift",
    idempotencyKey: `withdraw-${shiftId}`,
    payload: { shift_id: shiftId },
    eventId,
    detail: shiftId,
  });
}

/**
 * Where a shift stands for the person reading it.
 *
 * Signed up first, because being on a shift is the fact that matters most about
 * it and stays true after the schedule locks. Then cancelled, then whether it is
 * open — everything else is the node's refusal, which the row prints in full.
 */
export function shiftBoardStateLabel(shift: ShiftBoardEntry): string {
  if (shift.signedUp) {
    return "Signed up";
  }

  if (shift.cancelledAt !== null) {
    return "Cancelled";
  }

  return shift.canSignUp ? "Open" : "Unavailable";
}

export function shiftBoardWindowLabel(shift: ShiftBoardEntry): string {
  if (shift.startsAt === null || shift.endsAt === null) {
    return "Not scheduled";
  }

  return `${new Date(shift.startsAt).toLocaleString()} - ${new Date(
    shift.endsAt,
  ).toLocaleString()}`;
}

/**
 * How full the shift is (SHIFT-007, SHIFT-012).
 *
 * The count is the node's; a shift with no cap says so rather than showing a
 * denominator it does not have, and a copy that arrived without a count says
 * that rather than printing a zero. "0 of 4 signed up" on a shift somebody is
 * standing on is not a stale number, it is a wrong one.
 */
export function shiftBoardCapacityLabel(shift: ShiftBoardEntry): string {
  if (shift.activeAssignmentCount === null) {
    return shift.capacity === null
      ? "Signed-up count unavailable offline"
      : `Capacity ${shift.capacity}; signed-up count unavailable offline`;
  }

  return shift.capacity === null
    ? `${shift.activeAssignmentCount} signed up`
    : `${shift.activeAssignmentCount} of ${shift.capacity} signed up`;
}
