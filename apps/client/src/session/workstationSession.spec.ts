import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  authorFieldReportCatalog,
  discardFieldReportsOutsideEvent,
  persistFieldReportRuntime,
  resetFieldReportRuntime,
} from "@/field-reports/fieldReportRuntime";
import { submitFieldReport } from "@/field-reports/submitFieldReport";
import {
  createOfflineFieldReport,
  FIELD_REPORT_ACCEPTED,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { clearClientSession, clientSessionState } from "@/session/clientSession";
import { localFieldSessionDocument } from "@/session/localFieldSessionFixture";
import { readCachedSession } from "@/session/sessionCache";
import { registerSessionContextReset } from "@/session/sessionContext";
import {
  continueWorkstationSession,
  endWorkstationSession,
  enterWorkstationLoginCode,
  evaluateWorkstationSession,
  recordWorkstationActivity,
  resetWorkstationSession,
  workstationCredentialHeaders,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * Shared-workstation session semantics (M16.9; AUTH-030; technical spec 13.3;
 * kiosk guide 4.4).
 *
 * No server runs here (CLIENT-024). A fake node answers the three session
 * endpoints and `GET /api/me`, and records every request it is sent, so what the
 * workstation puts on the wire is itself part of what is asserted — which is the
 * only way to assert the negative AUTH-030 is about: that no bearer token is
 * involved anywhere in a code entry.
 */

const SESSION_KEY = "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh";
const SESSION_KEY_HEADER = "X-Meridian-Workstation-Session";
const WORKSTATION_ID = "workstation-gate-a";
const EVENT_ID = "event-1";

/** The node's inactivity deadline, moved by the tests that need it moved. */
const node = {
  expiresAt: "2027-06-01T12:05:00+00:00",
  /** Set when the node should refuse the next code entry. */
  refusal: null as { status: number; message: string; reason: string } | null,
  /** Set when the node considers the session over, however it ended. */
  sessionOver: false,
};

interface RecordedRequest {
  readonly method: string;
  readonly path: string;
  readonly sessionKey: string | null;
  readonly authorization: string | null;
  readonly body: string | null;
}

let requests: RecordedRequest[] = [];

function sessionResponse(withKey: boolean): Record<string, unknown> {
  return {
    ...(withKey ? { session_key: SESSION_KEY } : {}),
    session: {
      id: "session-1",
      started_at: "2027-06-01T12:00:00+00:00",
      last_activity_at: "2027-06-01T12:00:00+00:00",
      expires_at: node.expiresAt,
      inactivity_timeout_seconds: 300,
    },
    user: { id: "user-1", name: "Dana Reyes" },
    shared_workstation: {
      id: WORKSTATION_ID,
      name: "Gate A Workstation",
      organization_id: "org-1",
      department_id: null,
    },
    event_id: EVENT_ID,
  };
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function answer(method: string, path: string, sessionKey: string | null): Response {
  if (path === "/api/auth/shared-workstation-session") {
    if (method === "POST") {
      return node.refusal === null
        ? json(sessionResponse(true), 201)
        : json(
            { message: node.refusal.message, reason: node.refusal.reason },
            node.refusal.status,
          );
    }

    // Every other verb on this route carries the session key and nothing else,
    // so an unknown or finished session is refused exactly as the node's
    // `workstation` guard refuses it.
    if (sessionKey !== SESSION_KEY || node.sessionOver) {
      return json({ message: "That login code is not valid.", reason: "invalid_code" }, 401);
    }

    return method === "DELETE" ? json({ ended: true }) : json(sessionResponse(false));
  }

  if (path === "/api/me") {
    return sessionKey === SESSION_KEY
      ? json(localFieldSessionDocument())
      : json({ message: "Unauthenticated." }, 401);
  }

  return json({ message: "No route." }, 404);
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input));
      const method = (init?.method ?? "GET").toUpperCase();
      const headers = new Headers(init?.headers);
      const sessionKey = headers.get(SESSION_KEY_HEADER);

      requests.push({
        method,
        path: url.pathname,
        sessionKey,
        authorization: headers.get("Authorization"),
        body: typeof init?.body === "string" ? init.body : null,
      });

      return answer(method, url.pathname, sessionKey);
    }),
  );
}

async function signIn(): Promise<void> {
  const outcome = await enterWorkstationLoginCode({
    sharedWorkstationId: WORKSTATION_ID,
    code: "K3M7PQRS",
  });

  expect(outcome).toBe("signed_in");
}

function fieldReport(
  id: string,
  syncStatus: OfflineFieldReport["syncStatus"],
): OfflineFieldReport {
  return {
    ...createOfflineFieldReport(
      {
        eventId: EVENT_ID,
        submittedByUserId: "user-1",
        staffId: "staff-1",
        originDeviceId: "device-1",
        originNodeId: "node-1",
        title: "Radio handed back at Gate A",
        body: "Radio handed back at Gate A.",
      },
      { generateId: () => id, now: () => new Date("2027-06-01T12:01:00.000Z") },
    ),
    syncStatus,
  };
}

/** Submit a Field Report the way a surface does: catalog entry plus command. */
function queueFieldReport(id: string): void {
  submitFieldReport(
    {
      eventId: EVENT_ID,
      submittedByUserId: "user-1",
      staffId: "staff-1",
      originDeviceId: "device-1",
      originNodeId: "node-1",
      title: "Radio handed back at Gate A",
      body: "Radio handed back at Gate A.",
    },
    { generateId: () => id, now: () => new Date("2027-06-01T12:01:00.000Z") },
  );
}

/** Every value this device has written anywhere it survives a restart. */
function storedValues(): string[] {
  const values: string[] = [];

  for (const storage of [window.localStorage, window.sessionStorage]) {
    for (let index = 0; index < storage.length; index += 1) {
      const key = storage.key(index);

      values.push(key ?? "", key === null ? "" : (storage.getItem(key) ?? ""));
    }
  }

  return values;
}

let unregisterFieldReportReset: () => void;

beforeEach(() => {
  requests = [];
  node.expiresAt = "2027-06-01T12:05:00+00:00";
  node.refusal = null;
  node.sessionOver = false;

  window.localStorage.clear();
  window.sessionStorage.clear();
  resetWorkstationSession();
  clearClientSession();

  /*
   * What `main.ts` registers at boot. Registered here too, because the wipe a
   * session end performs runs the registry rather than a hardcoded list, and a
   * test with an empty registry would assert nothing about what survives it.
   */
  unregisterFieldReportReset = registerSessionContextReset((context) => {
    discardFieldReportsOutsideEvent(context.eventId);
  });

  stubNode();
  // No bearer token anywhere in these tests: a shared workstation has none, and
  // an accidental one would make the AUTH-030 assertions vacuous.
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
});

afterEach(async () => {
  unregisterFieldReportReset();
  resetWorkstationSession();
  clearClientSession();
  await resetFieldReportRuntime();
  resetCommandOutbox();
  configureMeridianApi(null);
  window.localStorage.clear();
  window.sessionStorage.clear();
  vi.unstubAllGlobals();
});

describe("entering a login code", () => {
  it("establishes a session and names the active user", async () => {
    // "The active user is shown prominently at all times" needs the name to be
    // in the state the shell reads, not something a surface fetches later.
    await signIn();

    expect(workstationSessionState.status).toBe("active");
    expect(workstationSessionState.user?.name).toBe("Dana Reyes");
    expect(workstationSessionState.workstation?.name).toBe("Gate A Workstation");
    expect(workstationSessionState.eventId).toBe(EVENT_ID);
    expect(workstationSessionState.endedReason).toBeNull();
  });

  it("issues no personal device token and sends no bearer credential", async () => {
    // AUTH-030 literally: a code entry establishes a shared-workstation session
    // and issues no API token. Every request the workstation makes carries the
    // session key in its own header and no `Authorization` header at all.
    await signIn();

    expect(requests.map((request) => `${request.method} ${request.path}`)).toEqual([
      "POST /api/auth/shared-workstation-session",
      "GET /api/me",
    ]);
    expect(requests.every((request) => request.authorization === null)).toBe(true);
    expect(requests.at(-1)?.sessionKey).toBe(SESSION_KEY);
    expect(workstationCredentialHeaders()).toEqual({
      [SESSION_KEY_HEADER]: SESSION_KEY,
    });
  });

  it("resolves the active user's permissions from the node", async () => {
    // "Permissions come entirely from the active user" (technical spec 13.3):
    // the session key authenticates `GET /api/me`, so there is one answer to
    // what this user may do rather than two that can disagree.
    await signIn();

    expect(clientSessionState.status).toBe("live");
    expect(clientSessionState.document?.capabilities.length ?? 0).toBeGreaterThan(0);
  });

  it("refuses a second code while a session is live", async () => {
    // "Switching users requires ending the current session first — there is no
    // quiet handover" (kiosk guide 4.4). Refused before any request is made, so
    // the workstation cannot spend somebody's code on a switch it will not do.
    await signIn();
    requests = [];

    const outcome = await enterWorkstationLoginCode({
      sharedWorkstationId: WORKSTATION_ID,
      code: "TVWX2345",
    });

    expect(outcome).toBe("session_active");
    expect(requests).toEqual([]);
    expect(workstationSessionState.user?.name).toBe("Dana Reyes");
  });

  it("stays locked and reports why when the node refuses the code", async () => {
    node.refusal = {
      status: 422,
      message: "That login code is not valid.",
      reason: "invalid_code",
    };

    const outcome = await enterWorkstationLoginCode({
      sharedWorkstationId: WORKSTATION_ID,
      code: "WRONG234",
    });

    expect(outcome).toBe("refused");
    expect(workstationSessionState.status).toBe("locked");
    expect(workstationSessionState.entryError).toBe("That login code is not valid.");
    expect(workstationCredentialHeaders()).toEqual({});
  });
});

describe("locking on restart", () => {
  it("writes the session key nowhere a restart could read it", async () => {
    await signIn();

    expect(storedValues().some((value) => value.includes(SESSION_KEY))).toBe(false);
  });

  it("keeps no session document a restart could come up holding", async () => {
    // The key being unrecoverable is not enough on its own. A cached `/api/me`
    // document would put the previous user's context back on screen for whoever
    // restarts the machine, which is the state a lock exists to prevent.
    await signIn();

    expect(readCachedSession()).toBeNull();
  });

  it("comes up locked, with nothing to restore", async () => {
    await signIn();

    // A restart, as honestly as this can be simulated: the module is discarded
    // and loaded again, with whatever the device wrote to disk still there.
    vi.resetModules();

    const restarted = await import("@/session/workstationSession");

    expect(restarted.workstationSessionState.status).toBe("locked");
    expect(restarted.workstationSessionState.user).toBeNull();
    expect(restarted.workstationCredentialHeaders()).toEqual({});
  });
});

describe("the inactivity timeout", () => {
  it("warns inside the last minute before the node's deadline", async () => {
    await signIn();

    evaluateWorkstationSession(new Date("2027-06-01T12:04:10+00:00"));

    expect(workstationSessionState.expiring).toBe(true);
    expect(workstationSessionState.status).toBe("active");
  });

  it("says nothing while there is time left", async () => {
    await signIn();

    evaluateWorkstationSession(new Date("2027-06-01T12:02:00+00:00"));

    expect(workstationSessionState.expiring).toBe(false);
  });

  it("locks when the node's deadline passes", async () => {
    await signIn();

    evaluateWorkstationSession(new Date("2027-06-01T12:05:00+00:00"));

    expect(workstationSessionState.status).toBe("locked");
    expect(workstationSessionState.endedReason).toBe("timed_out");
    expect(workstationCredentialHeaders()).toEqual({});
  });

  it("takes the deadline back from the node on activity", async () => {
    await signIn();
    node.expiresAt = "2027-06-01T12:09:30+00:00";

    recordWorkstationActivity(new Date("2027-06-01T12:04:30+00:00"));
    await vi.waitFor(() =>
      expect(workstationSessionState.expiresAt).toBe("2027-06-01T12:09:30+00:00"),
    );

    // The deadline it now holds is the node's, so the timeout it counts down to
    // is the one that will actually be enforced.
    evaluateWorkstationSession(new Date("2027-06-01T12:05:01+00:00"));

    expect(workstationSessionState.status).toBe("active");
  });

  it("slides the window when somebody answers the warning", async () => {
    await signIn();
    evaluateWorkstationSession(new Date("2027-06-01T12:04:10+00:00"));
    expect(workstationSessionState.expiring).toBe(true);

    node.expiresAt = "2027-06-01T12:09:10+00:00";
    await continueWorkstationSession();

    expect(workstationSessionState.expiring).toBe(false);
    expect(workstationSessionState.expiresAt).toBe("2027-06-01T12:09:10+00:00");
  });

  it("does not put a request on the wire for every keystroke", async () => {
    await signIn();
    requests = [];

    recordWorkstationActivity(new Date("2027-06-01T12:00:01+00:00"));
    await vi.waitFor(() => expect(requests).toHaveLength(1));

    recordWorkstationActivity(new Date("2027-06-01T12:00:02+00:00"));
    recordWorkstationActivity(new Date("2027-06-01T12:00:03+00:00"));

    expect(requests).toHaveLength(1);
  });

  it("always answers for activity inside the warning window", async () => {
    // The one window where dropping an interaction would sign out somebody who
    // is demonstrably still there.
    await signIn();
    recordWorkstationActivity(new Date("2027-06-01T12:00:01+00:00"));
    await vi.waitFor(() => expect(requests).not.toHaveLength(0));

    evaluateWorkstationSession(new Date("2027-06-01T12:04:10+00:00"));
    requests = [];
    recordWorkstationActivity(new Date("2027-06-01T12:04:11+00:00"));

    await vi.waitFor(() => expect(requests).toHaveLength(1));
  });

  it("locks when the node says the session it holds is already over", async () => {
    await signIn();
    node.sessionOver = true;

    await continueWorkstationSession();

    expect(workstationSessionState.status).toBe("locked");
    expect(workstationSessionState.endedReason).toBe("timed_out");
  });
});

describe("ending a session", () => {
  it("tells the node and wipes the session data", async () => {
    await signIn();
    requests = [];

    await endWorkstationSession("signed_out");

    expect(requests.map((request) => `${request.method} ${request.path}`)).toEqual([
      "DELETE /api/auth/shared-workstation-session",
    ]);
    expect(workstationSessionState.status).toBe("locked");
    expect(workstationSessionState.user).toBeNull();
    expect(workstationSessionState.endedReason).toBe("signed_out");
    expect(clientSessionState.document).toBeNull();
    expect(workstationCredentialHeaders()).toEqual({});
  });

  it("signs out anyway when the node cannot be reached", async () => {
    // Somebody walking away from a shared machine is entitled to be signed out
    // of it whatever the network is doing.
    await signIn();
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    await endWorkstationSession("signed_out");

    expect(workstationSessionState.status).toBe("locked");
    expect(clientSessionState.document).toBeNull();
  });

  it("leaves queued commands in the outbox", async () => {
    // Technical spec 13.3: "active session data is wiped ... Saved local queued
    // operations remain in the local queue and sync when available." A check-in
    // recorded and queued at a workstation is the only copy of work somebody did.
    // Since M16.10 that queue is the shared command outbox, which is deliberately
    // outside the context reset registry the wipe runs.
    await signIn();
    queueFieldReport("aaaaaaaa-1111-2222-3333-444455556666");
    authorFieldReportCatalog.recordSubmitted(
      fieldReport("bbbbbbbb-1111-2222-3333-444455556666", FIELD_REPORT_ACCEPTED),
    );
    persistFieldReportRuntime();

    await endWorkstationSession("signed_out");

    expect(commandOutbox.unsent().map((command) => command.idempotencyKey)).toEqual([
      "aaaaaaaa-1111-2222-3333-444455556666",
    ]);
    // The accepted report is the node's record and comes back with the catalog.
    // The pending one is not, so it stays.
    expect(authorFieldReportCatalog.snapshot().map((entry) => entry.id)).toEqual([
      "aaaaaaaa-1111-2222-3333-444455556666",
    ]);
  });

  it("leaves queued commands in the outbox on a timeout too", async () => {
    await signIn();
    queueFieldReport("cccccccc-1111-2222-3333-444455556666");

    evaluateWorkstationSession(new Date("2027-06-01T12:05:00+00:00"));

    expect(commandOutbox.unsent().map((command) => command.idempotencyKey)).toEqual([
      "cccccccc-1111-2222-3333-444455556666",
    ]);
  });

  it("makes no request on a timeout", async () => {
    // The node stamps a timed-out session at the moment it expired whenever it
    // next looks, so there is nothing for the workstation to report.
    await signIn();
    requests = [];

    await endWorkstationSession("timed_out");

    expect(requests).toEqual([]);
    expect(workstationSessionState.status).toBe("locked");
  });

  it("lets the next user sign in once the session is ended", async () => {
    await signIn();
    await endWorkstationSession("signed_out");

    const outcome = await enterWorkstationLoginCode({
      sharedWorkstationId: WORKSTATION_ID,
      code: "TVWX2345",
    });

    expect(outcome).toBe("signed_in");
    expect(workstationSessionState.status).toBe("active");
  });
});
