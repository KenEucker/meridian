import type { StatusPillTone } from "@/components/StatusPill.vue";
import type {
  DepartmentPresenceState,
  EquipmentAssignmentScope,
  EquipmentPresentationState,
  EquipmentState,
  EquipmentTracking,
  ShiftAttendanceState,
  ShiftLifecycle,
} from "@/department-ops/types";

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

export function presenceStateLabel(state: DepartmentPresenceState): string {
  switch (state) {
    case "on_site":
      return "On-site";
    case "off_site":
      return "Off-site";
  }
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

/**
 * What an operator reads on an outstanding checkout (EQUIP-005).
 *
 * Overdue and Unknown are derived by the node and are additions to the reading,
 * not to the five stored states UI contract 9.6 fixes. Unknown is the one worth
 * saying out loud: it means there is no window end to measure this checkout
 * against, so Meridian is declining to guess rather than reporting it on time.
 */
export function equipmentPresentationLabel(
  state: EquipmentPresentationState,
): string {
  switch (state) {
    case "overdue":
      return "Overdue";
    case "unknown":
      return "Unknown";
    default:
      return equipmentStateLabel(state);
  }
}

export function equipmentPresentationTone(
  state: EquipmentPresentationState,
): StatusPillTone {
  switch (state) {
    case "overdue":
      return "critical";
    // Not a warning. Nobody has done anything wrong; Meridian simply has no
    // window to measure against, and painting that red would teach an operator
    // to ignore the color on the state that does need acting on.
    case "unknown":
      return "caution";
    default:
      return equipmentTone(state);
  }
}

/** Shift kit or event kit (EQUIP-009). */
export function assignmentScopeLabel(scope: EquipmentAssignmentScope): string {
  return scope === "shift" ? "Shift" : "Event";
}

/** Tracked or Pooled, in UI contract 9.6A's words rather than the stored value's. */
export function equipmentTrackingLabel(tracking: EquipmentTracking): string {
  return tracking === "pooled" ? "Pooled" : "Tracked";
}

export function lifecycleLabel(lifecycle: ShiftLifecycle): string {
  switch (lifecycle) {
    case "upcoming":
      return "Upcoming";
    case "active":
      return "Active";
    case "completed":
      return "Completed";
    case "cancelled":
      return "Cancelled";
  }
}

/*
 * What each state costs whoever is reading it.
 *
 * The tones are a scale of consequence, not a restatement of the vocabulary, so
 * they are decided here beside the words rather than inside a template. Two of
 * them are worth saying out loud because the obvious mapping is wrong:
 *
 *   - Off-site is not a problem. Somebody who went off-site did so through this
 *     desk, with the checks that go with it, and painting it as a warning would
 *     teach an operator to ignore the color on the states where it matters.
 *   - No-show and missing are, and are the only two states on these three scales
 *     that somebody has to do something about.
 */
export function presenceTone(state: DepartmentPresenceState): StatusPillTone {
  return state === "on_site" ? "positive" : "neutral";
}

export function attendanceTone(state: ShiftAttendanceState): StatusPillTone {
  switch (state) {
    case "checked_in":
      return "positive";
    case "no_show":
      return "critical";
    case "excused":
    case "corrected":
      return "caution";
    case "checked_out":
    case "scheduled":
      return "neutral";
  }
}

export function equipmentTone(state: EquipmentState): StatusPillTone {
  switch (state) {
    case "checked_out":
      return "info";
    case "missing":
    case "damaged":
      return "critical";
    case "available":
    case "returned":
      return "neutral";
  }
}

export function lifecycleTone(lifecycle: ShiftLifecycle): StatusPillTone {
  switch (lifecycle) {
    case "active":
      return "positive";
    case "cancelled":
      return "critical";
    case "upcoming":
      return "info";
    case "completed":
      return "neutral";
  }
}

export function formatTimestamp(timestamp: string, timeZone: string): string {
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    timeZone,
    timeZoneName: "short",
  }).format(new Date(timestamp));
}
