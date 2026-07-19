import type {
  DepartmentPresenceState,
  EquipmentState,
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
