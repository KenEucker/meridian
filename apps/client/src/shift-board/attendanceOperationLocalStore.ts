// Temporary browser-local persistence for queued attendance operations (M10.5).
//
// PowerSync-backed durable encrypted local storage and signed operation
// envelopes remain later sync work. This store keeps the pending outbox
// recoverable across field-app refreshes for the current Alpha 1 seam.

import {
  ATTENDANCE_OPERATION_TYPES,
  ATTENDANCE_PENDING_SYNC,
  type OfflineAttendanceOperation,
} from "@/shift-board/offlineAttendanceOperation";

export const ATTENDANCE_OPERATION_LOCAL_STORE_KEY =
  "meridian.shift-board.attendance-outbox.v1";

interface StoredAttendanceOperationState {
  readonly version: 1;
  readonly operations: OfflineAttendanceOperation[];
}

export interface AttendanceOperationLocalStore {
  readonly load: () => OfflineAttendanceOperation[];
  readonly save: (operations: readonly OfflineAttendanceOperation[]) => void;
  readonly clear: () => void;
}

function isPendingAttendanceOperation(
  value: unknown,
): value is OfflineAttendanceOperation {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const operation = value as Record<string, unknown>;

  return (
    typeof operation.operationUuid === "string" &&
    typeof operation.operationType === "string" &&
    ATTENDANCE_OPERATION_TYPES.includes(
      operation.operationType as OfflineAttendanceOperation["operationType"],
    ) &&
    typeof operation.eventId === "string" &&
    typeof operation.departmentId === "string" &&
    typeof operation.shiftId === "string" &&
    typeof operation.staffId === "string" &&
    typeof operation.createdByUserId === "string" &&
    typeof operation.originDeviceId === "string" &&
    typeof operation.originNodeId === "string" &&
    typeof operation.deviceCreatedAt === "string" &&
    typeof operation.createdAt === "string" &&
    operation.syncStatus === ATTENDANCE_PENDING_SYNC &&
    operation.serverReceivedAt === null &&
    (operation.teamId === null || typeof operation.teamId === "string") &&
    (operation.shiftAssignmentId === null ||
      typeof operation.shiftAssignmentId === "string") &&
    (operation.actualStartedAt === null ||
      typeof operation.actualStartedAt === "string") &&
    (operation.actualEndedAt === null ||
      typeof operation.actualEndedAt === "string")
  );
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

export function createAttendanceOperationLocalStore(
  storage: Storage | null = readStorage(),
): AttendanceOperationLocalStore {
  let memoryFallback: OfflineAttendanceOperation[] = [];

  return {
    load(): OfflineAttendanceOperation[] {
      if (!storage) {
        return memoryFallback.map((operation) =>
          Object.freeze({ ...operation }),
        );
      }

      try {
        const raw = storage.getItem(ATTENDANCE_OPERATION_LOCAL_STORE_KEY);
        if (!raw) {
          return [];
        }

        const parsed = JSON.parse(raw) as Partial<StoredAttendanceOperationState>;
        if (parsed.version !== 1 || !Array.isArray(parsed.operations)) {
          return [];
        }

        return parsed.operations
          .filter(isPendingAttendanceOperation)
          .map((operation) => Object.freeze({ ...operation }));
      } catch {
        return [];
      }
    },

    save(operations: readonly OfflineAttendanceOperation[]): void {
      const payload: StoredAttendanceOperationState = {
        version: 1,
        operations: operations.map((operation) => ({ ...operation })),
      };

      if (!storage) {
        memoryFallback = payload.operations.map((operation) =>
          Object.freeze({ ...operation }),
        );
        return;
      }

      try {
        storage.setItem(
          ATTENDANCE_OPERATION_LOCAL_STORE_KEY,
          JSON.stringify(payload),
        );
      } catch {
        memoryFallback = payload.operations.map((operation) =>
          Object.freeze({ ...operation }),
        );
      }
    },

    clear(): void {
      memoryFallback = [];

      if (!storage) {
        return;
      }

      try {
        storage.removeItem(ATTENDANCE_OPERATION_LOCAL_STORE_KEY);
      } catch {
        // Keep reset best-effort; in-memory state is already empty.
      }
    },
  };
}

export const attendanceOperationLocalStore =
  createAttendanceOperationLocalStore();
