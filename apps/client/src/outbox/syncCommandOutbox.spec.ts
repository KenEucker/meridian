import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  authorFieldReportCatalog,
  resetFieldReportRuntime,
} from "@/field-reports/fieldReportRuntime";
import { submitFieldReport } from "@/field-reports/submitFieldReport";
import {
  commandOutbox,
  reloadCommandOutboxFromLocalStore,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { queueCommand } from "@/outbox/submitCommand";
import { syncCommandOutbox } from "@/outbox/syncCommandOutbox";
import { submitAttendanceOperation } from "@/shift-board/submitAttendanceOperation";

/*
 * Draining the queue (M16.10; CLIENT-015 through CLIENT-017; technical spec
 * 11A.5; data/API 5.3, 5.6).
 *
 * No server runs here (CLIENT-024). A stubbed `fetch` records every request the
 * device puts on the wire, which is how the idempotency assertions are made:
 * what matters is that one command produces one request carrying one key,
 * however many times the drain runs.
 */

const ATTENDANCE_INPUT = {
  eventId: "event-1",
  departmentId: "department-1",
  teamId: "team-1",
  shiftId: "shift-1",
  shiftAssignmentId: "assignment-1",
  staffId: "staff-1",
  createdByUserId: "user-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
} as const;

interface RecordedRequest {
  readonly url: string;
  readonly body: Record<string, unknown>;
}

function acceptAttendance(uuid: string, state = "checked_in"): Response {
  return new Response(
    JSON.stringify({
      operation_uuid: uuid,
      operation_id: `${uuid}-server`,
      attendance_record_id: "record-1",
      current_state: state,
      server_received_at: "2027-07-04T16:00:05.000Z",
      created_state_change: true,
    }),
    { status: 201, headers: { "Content-Type": "application/json" } },
  );
}

function recordingFetch(
  requests: RecordedRequest[],
  respond: (url: string, body: Record<string, unknown>) => Response,
): ReturnType<typeof vi.fn> {
  return vi.fn(async (input: RequestInfo, init?: RequestInit) => {
    const url = String(input);
    const body = JSON.parse(String(init?.body ?? "{}")) as Record<
      string,
      unknown
    >;
    requests.push({ url, body });

    return respond(url, body);
  });
}

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://127.0.0.1:8000",
    bearerToken: "device-token",
  });
});

afterEach(async () => {
  resetCommandOutbox();
  await resetFieldReportRuntime();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("syncCommandOutbox", () => {
  it("sends nothing without a credential", async () => {
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:8000",
      bearerToken: null,
    });
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);

    queueCommand({
      commandType: "check-in-staff",
      idempotencyKey: "11111111-1111-4111-8111-111111111111",
      payload: {},
    });

    const result = await syncCommandOutbox();

    expect(result.blockedReason).toBe(
      "This device is not signed in, so commands are waiting.",
    );
    expect(fetchMock).not.toHaveBeenCalled();
    expect(commandOutbox.pending()).toHaveLength(1);
  });

  it("drains Field Report and attendance commands through one pass", async () => {
    // Both are callers of the shared queue now, not owners of their own
    // (technical spec 11A.5), so one drain carries both.
    const requests: RecordedRequest[] = [];
    const report = submitFieldReport(
      {
        eventId: "event-1",
        submittedByUserId: "user-1",
        staffId: "staff-1",
        originDeviceId: "device-1",
        originNodeId: "node-1",
        title: "Radio handed back at Gate A",
        body: "Radio handed back at Gate A.",
      },
      {
        generateId: () => "aaaaaaaa-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T15:59:00.000Z"),
      },
    );
    const checkIn = submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "check_in" },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T16:00:00.000Z"),
      },
    );

    vi.stubGlobal(
      "fetch",
      recordingFetch(requests, (url) =>
        url.includes("submit-field-report")
          ? new Response(
              JSON.stringify({
                id: report.id,
                fra_number: "FRA-2027-000001",
                server_received_at: "2027-07-04T16:00:05.000Z",
                sync_status: "accepted",
              }),
              { status: 201, headers: { "Content-Type": "application/json" } },
            )
          : acceptAttendance(checkIn.operationUuid),
      ),
    );

    const result = await syncCommandOutbox();

    expect(result.attempted).toBe(2);
    expect(result.accepted).toBe(2);
    expect(requests.map((request) => request.url)).toEqual([
      "http://127.0.0.1:8000/api/commands/submit-field-report",
      "http://127.0.0.1:8000/api/commands/check-in-staff",
    ]);
    // The acceptance is applied where the command left local state behind.
    expect(authorFieldReportCatalog.get(report.id)?.fraNumber).toBe(
      "FRA-2027-000001",
    );
    expect(commandOutbox.byStatus("accepted")).toHaveLength(2);
    expect(commandOutbox.pending()).toEqual([]);
  });

  it("sends a repeated drain no second copy of an accepted command", async () => {
    // Replay after an interrupted sync is safe because the key is the command:
    // an accepted command is not re-sent, and if it were the node would treat
    // the repeated key as the same command (data/API 5.3).
    const requests: RecordedRequest[] = [];
    const checkIn = submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "check_in" },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T16:00:00.000Z"),
      },
    );
    vi.stubGlobal(
      "fetch",
      recordingFetch(requests, () => acceptAttendance(checkIn.operationUuid)),
    );

    await syncCommandOutbox();
    await syncCommandOutbox();
    reloadCommandOutboxFromLocalStore();
    await syncCommandOutbox();

    expect(requests).toHaveLength(1);
    expect(requests[0]?.body).toMatchObject({
      operation_uuid: "11111111-1111-4111-8111-111111111111",
    });
  });

  it("re-sends an interrupted command under the same key", async () => {
    const requests: RecordedRequest[] = [];
    const checkIn = submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "check_in" },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T16:00:00.000Z"),
      },
    );

    let firstAttempt = true;
    vi.stubGlobal(
      "fetch",
      recordingFetch(requests, () => {
        if (firstAttempt) {
          firstAttempt = false;

          throw new TypeError("Failed to fetch");
        }

        return acceptAttendance(checkIn.operationUuid);
      }),
    );

    const failed = await syncCommandOutbox();
    expect(failed.retryable).toBe(1);
    expect(commandOutbox.get(checkIn.operationUuid)?.status).toBe("queued");
    expect(commandOutbox.get(checkIn.operationUuid)?.statusReason).toBe(
      "Failed to fetch",
    );

    const retried = await syncCommandOutbox();

    expect(retried.accepted).toBe(1);
    expect(requests).toHaveLength(2);
    expect(
      requests.every(
        (request) =>
          request.body.operation_uuid ===
          "11111111-1111-4111-8111-111111111111",
      ),
    ).toBe(true);
  });

  it("keeps a refused command visible with the node's reason", async () => {
    // A rejected command is surfaced, not silently discarded (CLIENT-017).
    const noShow = submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "mark_no_show" },
      {
        generateId: () => "33333333-3333-4333-8333-333333333333",
        now: () => new Date("2027-07-04T16:10:00.000Z"),
      },
    );
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({ message: "That staff member is checked in." }),
            { status: 422, headers: { "Content-Type": "application/json" } },
          ),
      ),
    );

    const result = await syncCommandOutbox();

    expect(result.rejected).toBe(1);
    const rejected = commandOutbox.get(noShow.operationUuid);
    expect(rejected?.status).toBe("rejected");
    expect(rejected?.statusReason).toBe("That staff member is checked in.");
    // It stays held. Dropping it is how a shift lead never learns their no-show
    // did not take.
    reloadCommandOutboxFromLocalStore();
    expect(commandOutbox.get(noShow.operationUuid)?.status).toBe("rejected");
  });

  it("keeps a command whose credential lapsed rather than rejecting it", async () => {
    // 401 refuses the request, not the command. The work is still valid and a
    // person who recorded a check-in should not lose it because their token
    // expired while they were out of coverage.
    const checkIn = submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "check_in" },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T16:00:00.000Z"),
      },
    );
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify({ message: "Unauthenticated." }), {
            status: 401,
            headers: { "Content-Type": "application/json" },
          }),
      ),
    );

    const result = await syncCommandOutbox();

    expect(result.retryable).toBe(1);
    expect(commandOutbox.get(checkIn.operationUuid)?.status).toBe("queued");
  });

  it("does not let one stuck command hold up the ones behind it", async () => {
    const requests: RecordedRequest[] = [];
    submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "mark_no_show" },
      {
        generateId: () => "33333333-3333-4333-8333-333333333333",
        now: () => new Date("2027-07-04T16:10:00.000Z"),
      },
    );
    const checkIn = submitAttendanceOperation(
      { ...ATTENDANCE_INPUT, operationType: "check_in" },
      {
        generateId: () => "11111111-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T16:11:00.000Z"),
      },
    );

    vi.stubGlobal(
      "fetch",
      recordingFetch(requests, (url) =>
        url.includes("mark-no-show")
          ? new Response(JSON.stringify({ message: "conflict" }), {
              status: 422,
              headers: { "Content-Type": "application/json" },
            })
          : acceptAttendance(checkIn.operationUuid),
      ),
    );

    const result = await syncCommandOutbox();

    expect(result).toMatchObject({ attempted: 2, accepted: 1, rejected: 1 });
    expect(commandOutbox.get(checkIn.operationUuid)?.status).toBe("accepted");
  });

  it("keeps a command queued when the acceptance is unusable", async () => {
    // A Field Report with no FRA number has not really been accepted: the local
    // record would be left claiming a server identity it was never given
    // (technical spec 17.5).
    const report = submitFieldReport(
      {
        eventId: "event-1",
        submittedByUserId: "user-1",
        staffId: "staff-1",
        originDeviceId: "device-1",
        originNodeId: "node-1",
        title: "Radio handed back at Gate A",
        body: "Radio handed back at Gate A.",
      },
      {
        generateId: () => "aaaaaaaa-1111-4111-8111-111111111111",
        now: () => new Date("2027-07-04T15:59:00.000Z"),
      },
    );
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify({ id: report.id }), {
            status: 201,
            headers: { "Content-Type": "application/json" },
          }),
      ),
    );

    const result = await syncCommandOutbox();

    expect(result.retryable).toBe(1);
    expect(commandOutbox.get(report.id)?.status).toBe("queued");
  });
});
