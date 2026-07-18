import {
  createOfflineAttendanceOperation,
  type CreateOfflineAttendanceOperationInput,
  type OfflineAttendanceOperation,
  type OfflineAttendanceOperationDependencies,
} from "@/shift-board/offlineAttendanceOperation";
import {
  pendingAttendanceQueue,
  persistAttendanceRuntime,
} from "@/shift-board/attendanceRuntime";
import { PendingAttendanceQueue } from "@/shift-board/pendingAttendanceQueue";

export interface SubmitAttendanceOperationDependencies
  extends OfflineAttendanceOperationDependencies {
  readonly queue?: PendingAttendanceQueue;
  readonly notifyQueueChanged?: () => void;
}

/**
 * Create a local attendance operation and enqueue it for later sync.
 * Idempotency is by the device-generated operation UUID.
 */
export function submitAttendanceOperation(
  input: CreateOfflineAttendanceOperationInput,
  dependencies: SubmitAttendanceOperationDependencies = {},
): OfflineAttendanceOperation {
  const queue = dependencies.queue ?? pendingAttendanceQueue;
  const notify =
    dependencies.notifyQueueChanged ?? persistAttendanceRuntime;

  const operation = createOfflineAttendanceOperation(input, {
    generateId: dependencies.generateId,
    now: dependencies.now,
  });

  queue.enqueue(operation);
  notify();

  return operation;
}

export function markAttendanceOperationSynced(
  operationUuid: string,
  dependencies: SubmitAttendanceOperationDependencies = {},
): boolean {
  const queue = dependencies.queue ?? pendingAttendanceQueue;
  const notify =
    dependencies.notifyQueueChanged ?? persistAttendanceRuntime;
  const removed = queue.markSynced(operationUuid);

  if (removed) {
    notify();
  }

  return removed;
}
