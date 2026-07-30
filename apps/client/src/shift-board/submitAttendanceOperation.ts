// Record an attendance operation on the device (M10.5; M16.10).
//
// Check-in, check-out, and mark-no-show are Alpha 1 offline writes (data/API
// 7.2), so a shift lead records them whether or not the node is reachable and
// the device is the only copy until it is. Since M16.10 that copy lives in the
// shared command outbox rather than an attendance queue of its own (technical
// spec 11A.5): the operation UUID the device generates is the command's
// idempotency key, so a retried, replayed, or restarted submission is the same
// command and the node applies it once.

import {
  attendanceCommandPayload,
  attendanceCommandType,
} from "@/shift-board/submitAttendanceOperationCommand";
import {
  createOfflineAttendanceOperation,
  type CreateOfflineAttendanceOperationInput,
  type OfflineAttendanceOperation,
  type OfflineAttendanceOperationDependencies,
} from "@/shift-board/offlineAttendanceOperation";
import { queueCommand } from "@/outbox/submitCommand";

/**
 * Create a local attendance operation and queue its command for submission.
 * Idempotency is by the device-generated operation UUID.
 */
export function submitAttendanceOperation(
  input: CreateOfflineAttendanceOperationInput,
  dependencies: OfflineAttendanceOperationDependencies = {},
): OfflineAttendanceOperation {
  const operation = createOfflineAttendanceOperation(input, dependencies);

  queueCommand({
    commandType: attendanceCommandType(operation.operationType),
    idempotencyKey: operation.operationUuid,
    payload: attendanceCommandPayload(operation),
    eventId: operation.eventId,
    detail: operation.staffId,
  });

  return operation;
}
