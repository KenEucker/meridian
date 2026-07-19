import { describe, expect, it } from "vitest";

import {
  createOfflineAttendanceOperation,
  type AttendanceOperationType,
  type OfflineAttendanceOperation,
} from "@/shift-board/offlineAttendanceOperation";
import {
  PendingAttendanceQueue,
  PendingAttendanceQueueError,
} from "@/shift-board/pendingAttendanceQueue";

const BASE_INPUT = {
  eventId: "event-1",
  departmentId: "department-1",
  teamId: "team-1",
  shiftId: "shift-1",
  shiftAssignmentId: "assignment-1",
  staffId: "staff-1",
  createdByUserId: "user-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
};

function operationWithId(
  operationUuid: string,
  operationType: AttendanceOperationType = "check_in",
): OfflineAttendanceOperation {
  return createOfflineAttendanceOperation(
    {
      ...BASE_INPUT,
      operationType,
      actualStartedAt:
        operationType === "check_out" ? "2027-07-04T16:00:00.000Z" : null,
      actualEndedAt:
        operationType === "check_out" ? "2027-07-04T22:00:00.000Z" : null,
    },
    {
      generateId: () => operationUuid,
      now: () => new Date("2027-07-04T16:00:00.000Z"),
    },
  );
}

describe("PendingAttendanceQueue", () => {
  it("holds a pending attendance operation in local queue state", () => {
    const queue = new PendingAttendanceQueue();
    const operation = operationWithId("11111111-1111-4111-8111-111111111111");

    expect(queue.enqueue(operation)).toBe(true);
    expect(queue.size).toBe(1);
    expect(queue.has(operation.operationUuid)).toBe(true);
    expect(queue.get(operation.operationUuid)).toBe(operation);
    expect(queue.pending()).toEqual([operation]);
  });

  it("is idempotent by operation UUID", () => {
    const queue = new PendingAttendanceQueue();
    const operation = operationWithId("11111111-1111-4111-8111-111111111111");

    expect(queue.enqueue(operation)).toBe(true);
    expect(queue.enqueue(operation)).toBe(false);
    expect(queue.enqueue({ ...operation, staffId: "staff-retry" })).toBe(false);

    expect(queue.size).toBe(1);
    expect(queue.get(operation.operationUuid)?.staffId).toBe("staff-1");
  });

  it("preserves operation order", () => {
    const queue = new PendingAttendanceQueue();
    const checkIn = operationWithId("11111111-1111-4111-8111-111111111111");
    const checkOut = operationWithId(
      "22222222-2222-4222-8222-222222222222",
      "check_out",
    );

    queue.enqueue(checkIn);
    queue.enqueue(checkOut);

    expect(queue.pending().map((operation) => operation.operationUuid)).toEqual([
      checkIn.operationUuid,
      checkOut.operationUuid,
    ]);
  });

  it("rejects accepted operations", () => {
    const queue = new PendingAttendanceQueue();
    const accepted: OfflineAttendanceOperation = {
      ...operationWithId("11111111-1111-4111-8111-111111111111"),
      syncStatus: "accepted",
      serverReceivedAt: "2027-07-04T16:00:05.000Z",
    };

    expect(() => queue.enqueue(accepted)).toThrow(PendingAttendanceQueueError);
    expect(queue.size).toBe(0);
  });

  it("drains synced operations idempotently without removing unrelated failures", () => {
    const queue = new PendingAttendanceQueue();
    const first = operationWithId("11111111-1111-4111-8111-111111111111");
    const second = operationWithId("22222222-2222-4222-8222-222222222222");
    queue.enqueue(first);
    queue.enqueue(second);

    expect(queue.markSynced(first.operationUuid)).toBe(true);
    expect(queue.markSynced(first.operationUuid)).toBe(false);

    expect(queue.pending().map((operation) => operation.operationUuid)).toEqual([
      second.operationUuid,
    ]);
  });
});
