import {
  ATTENDANCE_PENDING_SYNC,
  type OfflineAttendanceOperation,
} from "@/shift-board/offlineAttendanceOperation";

export class PendingAttendanceQueueError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "PendingAttendanceQueueError";
  }
}

/**
 * Ordered, UUID-keyed outbox of offline attendance operations awaiting sync.
 * Insertion order is preserved so actions replay in the order the device
 * created them, while each UUID stays idempotent across retries.
 */
export class PendingAttendanceQueue {
  private readonly operations = new Map<string, OfflineAttendanceOperation>();

  enqueue(operation: OfflineAttendanceOperation): boolean {
    if (operation.syncStatus !== ATTENDANCE_PENDING_SYNC) {
      throw new PendingAttendanceQueueError(
        "Only pending-sync attendance operations can be queued for sync.",
      );
    }

    if (this.operations.has(operation.operationUuid)) {
      return false;
    }

    this.operations.set(operation.operationUuid, operation);

    return true;
  }

  has(operationUuid: string): boolean {
    return this.operations.has(operationUuid);
  }

  get(operationUuid: string): OfflineAttendanceOperation | undefined {
    return this.operations.get(operationUuid);
  }

  pending(): readonly OfflineAttendanceOperation[] {
    return Array.from(this.operations.values());
  }

  get size(): number {
    return this.operations.size;
  }

  markSynced(operationUuid: string): boolean {
    return this.operations.delete(operationUuid);
  }

  replaceAll(operations: readonly OfflineAttendanceOperation[]): void {
    this.operations.clear();

    for (const operation of operations) {
      this.enqueue(operation);
    }
  }

  clear(): void {
    this.operations.clear();
  }
}
