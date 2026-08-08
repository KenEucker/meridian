// The read models on the offline read set (M18.50; CLIENT-001, CLIENT-015,
// CLIENT-021; technical spec 9.3, 17.2).
//
// What is under test is the seam and the projections behind it: with the node
// unreachable, a migrated read answers from the set this device is holding, says
// which copy that is, and refuses where the set may not be served. With the node
// answering, nothing here runs at all — a projection that could beat a live read
// would be a device deciding it knows better.
//
// No server runs for any of it (CLIENT-024). `fetch` is stubbed, and the set is
// installed through the same pull a device performs.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import { getMyAcknowledgments } from "@/documents/documentAcknowledgmentModel";
import { getEventFieldReports } from "@/ims/incidentReadModel";
import {
  installOfflineReadSet,
  offlineReadSetPayload,
} from "@/offline/offlineReadSetFixture";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { getShiftBoard } from "@/shift-board/staffShiftBoardModel";
import { resetCommandOutbox } from "@/outbox/commandOutboxRuntime";
import { queueCommand } from "@/outbox/submitCommand";

const EVENT_ID = "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee";
const SHIFT_ID = "55555555-5555-4555-8555-555555555555";
const STAFF_ID = "66666666-6666-4666-8666-666666666666";
const POLICY_ID = "77777777-7777-4777-8777-777777777777";
const ACCEPTED_REPORT_ID = "88888888-8888-4888-8888-888888888881";
const PENDING_REPORT_ID = "88888888-8888-4888-8888-888888888882";

const STORED_AT = "2027-07-04T18:00:00.000Z";

function unreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

function answeringNode(body: unknown): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

function refusingNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify({ message: "This action is unauthorized." }), {
          status: 403,
          headers: { "content-type": "application/json" },
        }),
    ),
  );
}

/** A set whose event window is open, so 11A.4 lets it be served. */
async function installSet(
  sections: Readonly<Record<string, readonly Record<string, unknown>[]>>,
  readiness: Record<string, unknown> = {},
): Promise<void> {
  await installOfflineReadSet(
    offlineReadSetPayload({
      sections,
      readiness: {
        context_event_id: EVENT_ID,
        usable_until: "2099-01-01T00:00:00+00:00",
        ...readiness,
      },
    }),
    { organizationId: null, eventId: EVENT_ID },
  );
}

function shiftRow(): Record<string, unknown> {
  return {
    id: SHIFT_ID,
    event_id: EVENT_ID,
    department_id: "department-1",
    eligible_team_id: "team-1",
    title: "Gate Swing",
    department_name_snapshot: "Gate",
    team_name_snapshot: "Gate Crew",
    starts_at: "2027-07-04T18:00:00+00:00",
    ends_at: "2027-07-04T22:00:00+00:00",
    capacity: 4,
    signup_opens_at: null,
    signup_closes_at: null,
    schedule_lock_at: null,
    cancelled_at: null,
  };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date(STORED_AT));
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: "token" });
  clearOfflineReadSet();
  resetCommandOutbox();
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  configureMeridianApi(null);
  clearOfflineReadSet();
  resetCommandOutbox();
});

describe("the staff shift board on the offline read set", () => {
  it("renders the shifts the device holds when the node cannot be reached", async () => {
    await installSet({
      events: [{ id: EVENT_ID, name: "Local Field Event" }],
      shifts: [shiftRow()],
      shift_assignments: [
        {
          id: "assignment-1",
          shift_id: SHIFT_ID,
          staff_id: STAFF_ID,
          assignment_status: "confirmed",
        },
      ],
    });

    unreachableNode();

    const board = await getShiftBoard(EVENT_ID);

    expect(board.eventName).toBe("Local Field Event");
    expect(board.shifts).toHaveLength(1);
    expect(board.shifts[0]?.title).toBe("Gate Swing");
    expect(board.shifts[0]?.signedUp).toBe(true);
    expect(board.shifts[0]?.assignmentStatus).toBe("confirmed");
  });

  /*
   * The disclosure is what a surface prints, and it did not change when the
   * store under it did: a stored read is `cache` with the moment the device took
   * delivery of the set on it (UI implementation contract 16.2).
   */
  it("discloses a stored read exactly as it always has", async () => {
    await installSet({
      events: [{ id: EVENT_ID, name: "Local Field Event" }],
      shifts: [shiftRow()],
      shift_assignments: [],
    });

    unreachableNode();

    const board = await getShiftBoard(EVENT_ID);

    expect(board.freshness.source).toBe("cache");
    expect(board.freshness.cachedAt).toBe(STORED_AT);
    expect(board.freshness.narrowed).toBe(false);
  });

  it("reports a live read as live and does not consult the set", async () => {
    await installSet({
      events: [{ id: EVENT_ID, name: "Held Copy" }],
      shifts: [shiftRow()],
      shift_assignments: [],
    });

    answeringNode({ event: { id: EVENT_ID, name: "From The Node" }, shifts: [] });

    const board = await getShiftBoard(EVENT_ID);

    expect(board.freshness.source).toBe("node");
    expect(board.freshness.cachedAt).toBeNull();
    expect(board.eventName).toBe("From The Node");
    expect(board.shifts).toHaveLength(0);
  });

  /*
   * A refusal is the node speaking. Answering it from the set would be the
   * client re-granting what the node had just withdrawn (CLIENT-006, CLIENT-010).
   */
  it("does not answer a refusal from the set", async () => {
    await installSet({
      events: [{ id: EVENT_ID, name: "Local Field Event" }],
      shifts: [shiftRow()],
      shift_assignments: [],
    });

    refusingNode();

    await expect(getShiftBoard(EVENT_ID)).rejects.toThrow(/unauthorized/);
  });

  /*
   * Technical spec 11A.4: a set is usable for the duration of the event it was
   * composed for. Past that it is refused rather than disclosed, which the seam
   * applies once for every projection (M18.49).
   */
  it("refuses a set past the window of the event it was composed for", async () => {
    await installSet(
      {
        events: [{ id: EVENT_ID, name: "Local Field Event" }],
        shifts: [shiftRow()],
        shift_assignments: [],
      },
      { usable_until: "2027-07-04T00:00:00+00:00" },
    );

    unreachableNode();

    await expect(getShiftBoard(EVENT_ID)).rejects.toThrow(/Failed to fetch/);
  });

  it("states the unreachable node when the device holds no set at all", async () => {
    unreachableNode();

    await expect(getShiftBoard(EVENT_ID)).rejects.toThrow(/Failed to fetch/);
  });
});

describe("Field Reports unioned with the outbox", () => {
  function storedReport(): Record<string, unknown> {
    return {
      id: ACCEPTED_REPORT_ID,
      event_id: EVENT_ID,
      staff_id: STAFF_ID,
      fra_number: "FRA-2027-000014",
      temporary_local_number: "LOCAL-88888888",
      title: "Generator noise",
      body: "Reported at the north gate.",
      sync_status: "accepted",
      device_submitted_at: "2027-07-04T17:00:00+00:00",
      server_received_at: "2027-07-04T17:01:00+00:00",
      created_at: "2027-07-04T17:00:00+00:00",
    };
  }

  function queueReport(id: string, title: string): void {
    queueCommand({
      commandType: "submit-field-report",
      idempotencyKey: id,
      eventId: EVENT_ID,
      detail: "LOCAL-88888888",
      payload: {
        id,
        event_id: EVENT_ID,
        staff_id: STAFF_ID,
        temporary_local_number: "LOCAL-88888888",
        title,
        body: "Written where there was no signal.",
        device_submitted_at: "2027-07-04T17:30:00+00:00",
        origin_device_id: "device-1",
      },
    });
  }

  it("shows a report this device has not sent yet beside the ones the node has", async () => {
    await installSet({
      staff: [
        { id: STAFF_ID, legal_name: "Dana Reyes", preferred_name: "Dana" },
      ],
      field_reports: [storedReport()],
      field_report_appends: [],
    });

    queueReport(PENDING_REPORT_ID, "Water point empty");
    unreachableNode();

    const { reports, freshness } = await getEventFieldReports(EVENT_ID);

    expect(freshness.source).toBe("cache");
    expect(reports.map((report) => report.title)).toEqual([
      "Generator noise",
      "Water point empty",
    ]);
    expect(reports[0]?.authorName).toBe("Dana");
    // 17.5: the unsent one keeps its temporary local number.
    expect(reports[1]?.displayNumber).toBe("LOCAL-88888888");
  });

  /*
   * A Field Report has been keyed by a UUID the device minted since M9.1, and
   * the node stores that same key. So the queued command and the accepted record
   * are one report under one key — deduplicated by an identifier match rather
   * than by comparing titles and timestamps, which would collapse two genuine
   * reports filed a minute apart.
   */
  it("renders a pending report and its accepted copy once", async () => {
    await installSet({
      staff: [
        { id: STAFF_ID, legal_name: "Dana Reyes", preferred_name: "Dana" },
      ],
      field_reports: [storedReport()],
      field_report_appends: [],
    });

    // The same report: still in the outbox, and already accepted by the node.
    queueReport(ACCEPTED_REPORT_ID, "Generator noise");
    unreachableNode();

    const { reports } = await getEventFieldReports(EVENT_ID);

    expect(reports).toHaveLength(1);
    // The node's copy wins, so the FRA number it assigned is what is shown
    // rather than the temporary local number the device minted.
    expect(reports[0]?.displayNumber).toBe("FRA-2027-000014");
  });

  it("leaves a report queued for another event out of this one", async () => {
    await installSet({
      staff: [{ id: STAFF_ID, legal_name: "Dana Reyes", preferred_name: null }],
      field_reports: [],
      field_report_appends: [],
    });

    queueCommand({
      commandType: "submit-field-report",
      idempotencyKey: PENDING_REPORT_ID,
      eventId: "another-event",
      payload: { id: PENDING_REPORT_ID, title: "Elsewhere", body: "x" },
    });
    unreachableNode();

    const { reports } = await getEventFieldReports(EVENT_ID);

    expect(reports).toHaveLength(0);
  });
});

describe("acknowledgment state on the offline read set", () => {
  function acknowledgmentSections(
    acknowledgments: readonly Record<string, unknown>[],
  ): Record<string, readonly Record<string, unknown>[]> {
    return {
      organizations: [{ id: "org-1", name: "Northwood Collective" }],
      policy_documents: [
        {
          id: POLICY_ID,
          organization_id: "org-1",
          scope_type: "organization",
          scope_id: "org-1",
          title: "Volunteer Conduct",
          slug: "volunteer-conduct",
          markdown_source: "# Volunteer Conduct",
          document_revision: 2,
          fragment_revision: 1,
          published_at: "2027-06-01T12:00:00+00:00",
        },
      ],
      procedure_documents: [],
      document_acknowledgment_requirements: [
        {
          id: "requirement-1",
          organization_id: "org-1",
          scope_type: "organization",
          scope_id: "org-1",
          document_type: "policy",
          document_id: POLICY_ID,
          requirement_context: "signup",
        },
      ],
      document_acknowledgments: acknowledgments,
    };
  }

  it("answers what is outstanding without a node", async () => {
    await installSet(acknowledgmentSections([]));
    unreachableNode();

    const mine = await getMyAcknowledgments();

    expect(mine.freshness.source).toBe("cache");
    expect(mine.outstandingCount).toBe(1);
    expect(mine.requirements[0]?.documentTitle).toBe("Volunteer Conduct");
    expect(mine.requirements[0]?.scopeLabel).toBe(
      "Organization: Northwood Collective",
    );
    expect(mine.requirements[0]?.documentVersion).toBe("2.01");
    // The text is the node's render with fragments resolved (POL-022) and does
    // not travel; the surface says so rather than showing an empty document.
    expect(mine.requirements[0]?.renderedHtml).toBe("");
  });

  /*
   * POL-045: an acknowledgment made at an earlier version stays an
   * acknowledgment. The set carries the version it was made at so the row can
   * say the document moved without making it outstanding again.
   */
  it("keeps an acknowledgment made at an earlier version answered", async () => {
    await installSet(
      acknowledgmentSections([
        {
          id: "acknowledgment-1",
          staff_id: STAFF_ID,
          document_type: "policy",
          document_id: POLICY_ID,
          document_revision: 1,
          fragment_revision: 0,
          scope_type: "organization",
          scope_id: "org-1",
          acknowledged_at: "2027-06-02T09:00:00+00:00",
        },
      ]),
    );
    unreachableNode();

    const mine = await getMyAcknowledgments();

    expect(mine.outstandingCount).toBe(0);
    expect(mine.requirements[0]?.acknowledged).toBe(true);
    expect(mine.requirements[0]?.acknowledgedVersion).toBe("1.00");
    expect(mine.requirements[0]?.documentChangedSince).toBe(true);
  });
});

/*
 * Domain data lives in the offline read set and nowhere else (M18.50; technical
 * spec 9.3).
 *
 * `readCache.ts` held sixty arbitrary responses under `meridian.reads.v1`, and a
 * surface that reached past its read model into `localStorage` for records would
 * be that cache growing back one call site at a time. The modules below are the
 * device-local stores the client is meant to have — a credential, a device
 * identity, unsent work, a preference, and the read set's own durable mirror —
 * and each is named with what it holds so a new one has to be argued for rather
 * than added.
 */
describe("where domain data is allowed to live on the device", () => {
  const SOURCES = import.meta.glob("/src/**/*.{ts,vue}", {
    query: "?raw",
    import: "default",
    eager: true,
  }) as Record<string, string>;

  /** Module path to what it holds, for a failure that explains itself. */
  const DEVICE_LOCAL_STORES: Readonly<Record<string, string>> = {
    "/src/app/nodeConnection.ts": "the address of the node this device points at",
    "/src/branding/brandingProfile.ts":
      "the organization's branding, so a boot paints its identity before the network answers",
    "/src/components/AppShell.vue": "the shell's own display preferences",
    "/src/field-reports/fieldReportLocalStore.ts":
      "unsent Field Reports, which this device is the only copy of",
    "/src/field-reports/fieldReportRuntime.ts":
      "the author catalog behind the unsent Field Reports",
    "/src/field-reports/pendingFieldReportPhotoStore.ts":
      "unsent Field Report photos and their encryption key",
    "/src/ims/incidentReadModel.ts":
      "whether the incident list opens in view or edit mode: a preference, not a record",
    "/src/offline/offlineReadSetStorage.ts":
      "the offline read set's own durable mirror — the one place domain records belong",
    "/src/outbox/commandOutboxLocalStore.ts": "the command outbox",
    "/src/session/apiToken.ts": "the bearer token",
    "/src/session/deviceIdentity.ts": "this device's identity",
    "/src/session/kioskContext.ts": "the workstation's configured context",
    "/src/session/sessionAccess.ts":
      "which department the client is working in: a selection, not a record",
    "/src/session/sessionCache.ts": "the session document",
    "/src/session/workstationIdentity.ts": "the workstation's identity",
    "/src/session/workstationSession.ts": "the workstation session key",
    "/src/views/AboutView.vue": "the diagnostics this device reports about itself",
  };

  it("keeps every other production module out of localStorage", () => {
    const reaching = Object.entries(SOURCES)
      .filter(([path]) => !path.endsWith(".spec.ts"))
      .filter(([path]) => !(path in DEVICE_LOCAL_STORES))
      .filter(([, source]) => source.includes("localStorage"))
      .map(([path]) => path);

    expect(reaching).toEqual([]);
  });

  it("leaves no trace of the deleted read cache", () => {
    const remaining = Object.entries(SOURCES)
      .filter(
        ([, source]) =>
          source.includes("meridian.reads.v1") ||
          source.includes("offline/readCache"),
      )
      .map(([path]) => path);

    expect(remaining).toEqual([]);
  });
});
