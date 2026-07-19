import { describe, expect, it } from "vitest";

import { createAttendanceOperationLocalStore } from "@/shift-board/attendanceOperationLocalStore";
import { createOfflineAttendanceOperation } from "@/shift-board/offlineAttendanceOperation";

function queuedOperation() {
  return createOfflineAttendanceOperation(
    {
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
    },
    {
      generateId: () => "11111111-1111-4111-8111-111111111111",
      now: () => new Date("2027-07-04T16:00:00.000Z"),
    },
  );
}

describe("attendanceOperationLocalStore", () => {
  it("persists pending attendance operations for later sync", () => {
    const store = createAttendanceOperationLocalStore(null);
    const operation = queuedOperation();

    store.save([operation]);

    expect(store.load()).toEqual([operation]);
  });

  it("filters malformed or already accepted stored operations", () => {
    const storage = new Map<string, string>();
    const fakeStorage: Storage = {
      get length() {
        return storage.size;
      },
      clear: () => storage.clear(),
      getItem: (key: string) => storage.get(key) ?? null,
      key: (index: number) => Array.from(storage.keys())[index] ?? null,
      removeItem: (key: string) => {
        storage.delete(key);
      },
      setItem: (key: string, value: string) => {
        storage.set(key, value);
      },
    };
    const store = createAttendanceOperationLocalStore(fakeStorage);
    const operation = queuedOperation();

    fakeStorage.setItem(
      "meridian.shift-board.attendance-outbox.v1",
      JSON.stringify({
        version: 1,
        operations: [
          operation,
          { ...operation, operationUuid: "accepted", syncStatus: "accepted" },
          { bad: "data" },
        ],
      }),
    );

    expect(store.load()).toEqual([operation]);
  });
});
