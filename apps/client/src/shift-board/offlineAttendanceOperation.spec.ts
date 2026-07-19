import { describe, expect, it } from "vitest";

import {
  ATTENDANCE_PENDING_SYNC,
  applyAttendanceSyncAcceptance,
  createOfflineAttendanceOperation,
  OfflineAttendanceOperationError,
  type AttendanceOperationType,
} from "@/shift-board/offlineAttendanceOperation";

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

describe("createOfflineAttendanceOperation", () => {
  it("creates a pending check-in operation with a device UUID and timestamp", () => {
    const operation = createOfflineAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "check_in",
      },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T15:52:00.000Z"),
      },
    );

    expect(operation).toEqual({
      operationUuid: "11111111-1111-4111-8111-111111111111",
      operationType: "check_in",
      eventId: "event-1",
      departmentId: "department-1",
      teamId: "team-1",
      shiftId: "shift-1",
      shiftAssignmentId: "assignment-1",
      staffId: "staff-1",
      createdByUserId: "user-1",
      originDeviceId: "device-1",
      originNodeId: "node-1",
      deviceCreatedAt: "2027-07-04T15:52:00.000Z",
      actualStartedAt: null,
      actualEndedAt: null,
      serverReceivedAt: null,
      syncStatus: ATTENDANCE_PENDING_SYNC,
      createdAt: "2027-07-04T15:52:00.000Z",
    });
  });

  it("creates a check-out operation with supplied actual times", () => {
    const operation = createOfflineAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "check_out",
        actualStartedAt: "2027-07-04T16:00:00.000Z",
        actualEndedAt: "2027-07-04T22:15:00.000Z",
      },
      {
        generateId: () => "22222222-2222-4222-8222-222222222222",
        now: () => new Date("2027-07-04T22:15:00.000Z"),
      },
    );

    expect(operation.operationType).toBe("check_out");
    expect(operation.actualStartedAt).toBe("2027-07-04T16:00:00.000Z");
    expect(operation.actualEndedAt).toBe("2027-07-04T22:15:00.000Z");
  });

  it("defaults check-out actual end to the device timestamp", () => {
    const operation = createOfflineAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "check_out",
        actualStartedAt: "2027-07-04T16:00:00.000Z",
      },
      {
        generateId: () => "33333333-3333-4333-8333-333333333333",
        now: () => new Date("2027-07-04T22:00:00.000Z"),
      },
    );

    expect(operation.actualEndedAt).toBe("2027-07-04T22:00:00.000Z");
  });

  it("rejects actual times for non-checkout operations", () => {
    expect(() =>
      createOfflineAttendanceOperation(
        {
          ...BASE_INPUT,
          operationType: "check_in",
          actualStartedAt: "2027-07-04T16:00:00.000Z",
        },
        {
          generateId: () => "44444444-4444-4444-8444-444444444444",
        },
      ),
    ).toThrow(OfflineAttendanceOperationError);
  });

  it("rejects invalid checkout time ranges", () => {
    expect(() =>
      createOfflineAttendanceOperation(
        {
          ...BASE_INPUT,
          operationType: "check_out",
          actualStartedAt: "2027-07-04T22:00:00.000Z",
          actualEndedAt: "2027-07-04T21:59:00.000Z",
        },
        {
          generateId: () => "55555555-5555-4555-8555-555555555555",
        },
      ),
    ).toThrow("actual end time must be after actual start time");
  });

  it("rejects unknown operation types", () => {
    expect(() =>
      createOfflineAttendanceOperation(
        {
          ...BASE_INPUT,
          operationType: "clock_in" as AttendanceOperationType,
        },
        {
          generateId: () => "66666666-6666-4666-8666-666666666666",
        },
      ),
    ).toThrow("operation type must be check_in, check_out, or mark_no_show");
  });

  it("applies server acceptance without mutating the queued operation", () => {
    const operation = createOfflineAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "mark_no_show",
      },
      {
        generateId: () => "77777777-7777-4777-8777-777777777777",
        now: () => new Date("2027-07-04T16:10:00.000Z"),
      },
    );

    const accepted = applyAttendanceSyncAcceptance(operation, {
      operationUuid: operation.operationUuid,
      serverReceivedAt: "2027-07-04T16:10:05.000Z",
    });

    expect(operation.syncStatus).toBe("pending_sync");
    expect(accepted.syncStatus).toBe("accepted");
    expect(accepted.serverReceivedAt).toBe("2027-07-04T16:10:05.000Z");
  });
});
