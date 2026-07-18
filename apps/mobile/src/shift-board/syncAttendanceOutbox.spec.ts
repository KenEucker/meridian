import { afterEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  pendingAttendanceQueue,
  resetAttendanceRuntime,
} from "@/shift-board/attendanceRuntime";
import { submitAttendanceOperation } from "@/shift-board/submitAttendanceOperation";
import { syncAttendanceOutbox } from "@/shift-board/syncAttendanceOutbox";

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

afterEach(() => {
  vi.unstubAllGlobals();
  configureMeridianApi(null);
  resetAttendanceRuntime();
});

describe("syncAttendanceOutbox", () => {
  it("no-ops when no API token is configured", async () => {
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:8000",
      bearerToken: null,
    });

    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    const result = await syncAttendanceOutbox();

    expect(result).toEqual({
      attempted: 0,
      accepted: 0,
      failed: 0,
    });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("syncs accepted operations and leaves failed operations queued", async () => {
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:8000",
      bearerToken: "local-field-dev-token",
    });

    const checkIn = submitAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "check_in",
      },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T16:00:00.000Z"),
      },
    );
    const checkOut = submitAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "check_out",
        actualStartedAt: "2027-07-04T16:00:00.000Z",
        actualEndedAt: "2027-07-04T22:00:00.000Z",
      },
      {
        generateId: () => "22222222-2222-4222-8222-222222222222",
        now: () => new Date("2027-07-04T22:00:00.000Z"),
      },
    );
    const noShow = submitAttendanceOperation(
      {
        ...BASE_INPUT,
        operationType: "mark_no_show",
      },
      {
        generateId: () => "33333333-3333-4333-8333-333333333333",
        now: () => new Date("2027-07-04T16:10:00.000Z"),
      },
    );

    const requests: Array<{ url: string; body: Record<string, unknown> }> = [];
    const fetchMock = vi.fn(async (input: RequestInfo, init?: RequestInit) => {
      const url = String(input);
      requests.push({
        url,
        body: JSON.parse(String(init?.body ?? "{}")) as Record<string, unknown>,
      });

      if (url.includes("mark-no-show")) {
        return new Response(JSON.stringify({ message: "conflict" }), {
          status: 422,
          headers: { "Content-Type": "application/json" },
        });
      }

      const isCheckOut = url.includes("check-out-staff");
      const operationUuid = isCheckOut
        ? checkOut.operationUuid
        : checkIn.operationUuid;

      return new Response(
        JSON.stringify({
          operation_uuid: operationUuid,
          operation_id: `${operationUuid}-server`,
          attendance_record_id: "record-1",
          hours_worked_id: isCheckOut ? "hours-1" : undefined,
          current_state: isCheckOut ? "checked_out" : "checked_in",
          server_received_at: "2027-07-04T22:00:05.000Z",
          created_state_change: true,
          created_hours: isCheckOut ? true : undefined,
        }),
        { status: 201, headers: { "Content-Type": "application/json" } },
      );
    });
    vi.stubGlobal("fetch", fetchMock);

    const result = await syncAttendanceOutbox();

    expect(result).toEqual({
      attempted: 3,
      accepted: 2,
      failed: 1,
    });
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(requests.map((request) => request.url)).toEqual([
      "http://127.0.0.1:8000/api/commands/check-in-staff",
      "http://127.0.0.1:8000/api/commands/check-out-staff",
      "http://127.0.0.1:8000/api/commands/mark-no-show",
    ]);
    expect(requests[1]?.body).toMatchObject({
      operation_uuid: checkOut.operationUuid,
      actual_started_at: "2027-07-04T16:00:00.000Z",
      actual_ended_at: "2027-07-04T22:00:00.000Z",
    });
    expect(pendingAttendanceQueue.pending()).toEqual([noShow]);
  });
});
