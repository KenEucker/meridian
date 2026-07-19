import { attendanceOperationLocalStore } from "@/shift-board/attendanceOperationLocalStore";
import { PendingAttendanceQueue } from "@/shift-board/pendingAttendanceQueue";

export const pendingAttendanceQueue = new PendingAttendanceQueue();

function hydrateFromLocalStore(): void {
  pendingAttendanceQueue.replaceAll(attendanceOperationLocalStore.load());
}

hydrateFromLocalStore();

export function persistAttendanceRuntime(): void {
  attendanceOperationLocalStore.save(pendingAttendanceQueue.pending());
}

export function reloadAttendanceRuntimeFromLocalStore(): void {
  hydrateFromLocalStore();
}

export function resetAttendanceRuntime(): void {
  pendingAttendanceQueue.clear();
  attendanceOperationLocalStore.clear();
}
