import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearClientSession } from "@/session/clientSession";
import { localFieldSessionDocument } from "@/session/localFieldSessionFixture";
import { configureSharedWorkstationId } from "@/session/workstationIdentity";
import {
  noteWorkstationPresence,
  presentWorkstationSignIn,
  resetWorkstationSignIn,
  SIGN_IN_AWAKE_WINDOW_MS,
  SIGN_IN_POLL_INTERVAL_MS,
  tickWorkstationSignIn,
  workstationSignInState,
} from "@/session/workstationSignIn";
import {
  resetWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * The Kiosk's half of the scan path (M18.60; AUTH-032, AUTH-035; technical
 * spec 13.4).
 *
 * What is asserted: a locked workstation presents an open request as a QR
 * beside its name and short code; an expired request is replaced rather than
 * left rendered; an unreachable node falls back to the typed field with a
 * stated reason; and a collected grant opens the session with the key held in
 * memory and never written to disk — the same rule the typed path has held
 * since M16.9.
 */

/**
 * One clock for the whole spec. The awake window (M18.68) makes the module
 * time-relative, so presenting at one instant and ticking at another that is
 * minutes away would sleep the presentation mid-test.
 */
const NOW = new Date("2027-06-01T12:00:00+00:00");

/** `NOW` plus some seconds, for readability at the call sites. */
function at(seconds: number): Date {
  return new Date(NOW.getTime() + seconds * 1_000);
}

const WORKSTATION_ID = "workstation-gate-a";
const SESSION_KEY = "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh";
const PICKUP_SECRET = "pickup-secret-only-the-workstation-holds";

const node = {
  openCount: 0,
  granted: false,
  reachable: true,
  expiresAt: "2027-06-01T12:02:00+00:00",
  /** What the collect poll answers with: 200, or a status to refuse with. */
  collectStatus: 200,
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (!node.reachable) {
        throw new TypeError("fetch failed");
      }

      const path = new URL(String(input)).pathname;
      const method = (init?.method ?? "GET").toUpperCase();

      if (path === "/api/me") {
        return json(localFieldSessionDocument());
      }

      if (method === "POST" && path.endsWith("/sign-in-requests")) {
        node.openCount += 1;

        return json(
          {
            sign_in_request: {
              id: `request-${node.openCount}`,
              purpose: "sign_in",
              expires_at: node.expiresAt,
            },
            pickup_secret: PICKUP_SECRET,
            shared_workstation: {
              id: WORKSTATION_ID,
              name: "Gate A Workstation",
              short_code: "K3M7PQRS",
            },
            node: { id: "node-1", name: "onsite-command-1" },
            event_id: "event-1",
          },
          201,
        );
      }

      if (method === "POST" && path.endsWith("/collect")) {
        if (node.collectStatus !== 200) {
          return json(
            { message: node.collectStatus === 429 ? "Too Many Attempts." : "Gone." },
            node.collectStatus,
          );
        }

        if (!node.granted) {
          return json({ status: "pending" });
        }

        return json(
          {
            status: "collected",
            session_key: SESSION_KEY,
            session: {
              id: "session-1",
              started_at: "2027-06-01T12:00:30+00:00",
              last_activity_at: "2027-06-01T12:00:30+00:00",
              expires_at: "2027-06-01T12:05:30+00:00",
              inactivity_timeout_seconds: 300,
            },
            user: { id: "user-1", name: "Dana Reyes" },
            shared_workstation: {
              id: WORKSTATION_ID,
              name: "Gate A Workstation",
              organization_id: "org-1",
              department_id: null,
            },
            event_id: "event-1",
          },
          201,
        );
      }

      return json({ message: "Unexpected request in test." }, 500);
    }),
  );
}

/** How many calls the node has been sent, for asserting silence. */
function fetchCalls(): number {
  return (globalThis.fetch as unknown as { mock: { calls: unknown[] } }).mock.calls.length;
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

beforeEach(() => {
  node.openCount = 0;
  node.granted = false;
  node.reachable = true;
  node.expiresAt = "2027-06-01T12:02:00+00:00";
  node.collectStatus = 200;

  window.localStorage.clear();
  window.sessionStorage.clear();
  resetWorkstationSignIn();
  resetWorkstationSession();
  clearClientSession();
  configureSharedWorkstationId(WORKSTATION_ID);
  stubNode();
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
});

afterEach(() => {
  configureSharedWorkstationId(null);
  resetWorkstationSignIn();
  resetWorkstationSession();
  clearClientSession();
  configureMeridianApi(null);
  window.localStorage.clear();
  window.sessionStorage.clear();
  vi.unstubAllGlobals();
});

describe("presenting a sign-in request", () => {
  it("opens a request and presents its QR beside the name and short code", async () => {
    await presentWorkstationSignIn(NOW);

    expect(workstationSignInState.status).toBe("presenting");
    expect(workstationSignInState.requestId).toBe("request-1");
    expect(workstationSignInState.workstationName).toBe("Gate A Workstation");
    expect(workstationSignInState.shortCode).toBe("K3M7PQRS");

    // The QR carries the workstation id, the issuing node, and the request id
    // (technical spec 13.4) — public identifiers, and nothing else.
    expect(workstationSignInState.qrText).toContain(`w=${WORKSTATION_ID}`);
    expect(workstationSignInState.qrText).toContain("n=node-1");
    expect(workstationSignInState.qrText).toContain("r=request-1");
    expect(workstationSignInState.qrText).not.toContain(PICKUP_SECRET);
  });

  it("falls back to the typed field with a stated reason when the node is unreachable", async () => {
    // The M18.53 rule, held with no exception: a node that cannot be reached
    // leaves a statement, not a spinner that never resolves.
    node.reachable = false;

    await presentWorkstationSignIn(NOW);

    expect(workstationSignInState.status).toBe("unavailable");
    expect(workstationSignInState.qrText).toBeNull();
    expect(workstationSignInState.unavailableReason).toContain(
      "could not reach the node",
    );
    expect(workstationSignInState.unavailableReason).toContain(
      "Enter a login code instead",
    );
  });

  it("retries an unavailable presentation on a later tick", async () => {
    node.reachable = false;
    await presentWorkstationSignIn(NOW);

    node.reachable = true;

    // The retry waits a few ticks so an unreachable node is not hammered.
    for (let tick = 0; tick < 5; tick++) {
      await tickWorkstationSignIn(NOW);
    }

    expect(workstationSignInState.status).toBe("presenting");
  });
});

describe("the request lifecycle", () => {
  it("keeps the QR up when a poll is rate limited rather than opening another request", async () => {
    // The amplifier this guards against: a 429 says nothing about the request,
    // which is still open and may be granted a second later. Replacing it
    // turned one refused poll into a refused open, and opening is itself rate
    // limited (AUTH-037) — so a single rate limit became a loop that emptied
    // the budget and flickered the screen with no interaction at all.
    await presentWorkstationSignIn(NOW);

    const openedWith = node.openCount;
    node.collectStatus = 429;

    for (let tick = 0; tick < 4; tick++) {
      await tickWorkstationSignIn(at(10));
    }

    expect(node.openCount).toBe(openedWith);
    expect(workstationSignInState.status).toBe("presenting");
    expect(workstationSignInState.requestId).toBe("request-1");

    // And the grant that arrives afterwards is still collectable: the request
    // was never thrown away.
    node.collectStatus = 200;
    node.granted = true;

    let outcome: string = "waiting";
    for (let tick = 0; tick < 6 && outcome !== "signed_in"; tick++) {
      outcome = await tickWorkstationSignIn(at(20));
    }

    expect(outcome).toBe("signed_in");
  });

  it("opens a fresh request when the node says this one is gone", async () => {
    // A 404 or 410 *is* a verdict about the request: spent, expired, or never
    // one. That is the case a replacement is for.
    await presentWorkstationSignIn(NOW);
    node.collectStatus = 404;

    await tickWorkstationSignIn(at(10));

    expect(workstationSignInState.requestId).toBe("request-2");
    expect(workstationSignInState.status).toBe("presenting");
  });

  it("replaces an expired request rather than leaving a stale square rendered", async () => {
    await presentWorkstationSignIn(NOW);
    expect(workstationSignInState.requestId).toBe("request-1");

    node.expiresAt = "2027-06-01T12:06:00+00:00";

    // Somebody is here — otherwise the awake window (M18.68) closes at the same
    // two minutes the request expires at, and sleeping is the right answer
    // rather than refreshing.
    await noteWorkstationPresence(at(115));

    await tickWorkstationSignIn(at(121));

    expect(workstationSignInState.status).toBe("presenting");
    expect(workstationSignInState.requestId).toBe("request-2");
    expect(workstationSignInState.qrText).toContain("r=request-2");
  });

  it("keeps waiting while the request is granted by nobody", async () => {
    await presentWorkstationSignIn(NOW);

    const outcome = await tickWorkstationSignIn(at(10));

    expect(outcome).toBe("waiting");
    expect(workstationSignInState.status).toBe("presenting");
    expect(workstationSessionState.status).toBe("locked");
  });
});

describe("sleeping when nobody is there", () => {
  /*
   * M18.68. A scannable QR needs a live request behind it, so "always
   * scannable" first meant "always polling": a machine alone in a room
   * re-opened a request every two minutes and asked about it every three
   * seconds, all night, for nobody.
   */
  const AWAKE = NOW;
  const AFTER_WINDOW = new Date(AWAKE.getTime() + SIGN_IN_AWAKE_WINDOW_MS + 1_000);

  it("keeps the QR up through the handover window with no interaction at all", async () => {
    // The case no-touch exists for: one person ends their session, the next
    // walks up within a minute and scans a screen they never touched.
    await presentWorkstationSignIn(AWAKE);

    const stillInside = new Date(AWAKE.getTime() + SIGN_IN_AWAKE_WINDOW_MS - 1_000);
    await tickWorkstationSignIn(stillInside);

    expect(workstationSignInState.status).toBe("presenting");
    expect(workstationSignInState.qrText).not.toBeNull();
  });

  it("sleeps once the window passes, holding no request and asking nothing", async () => {
    await presentWorkstationSignIn(AWAKE);
    const openedWhileAwake = node.openCount;

    await tickWorkstationSignIn(AFTER_WINDOW);

    expect(workstationSignInState.status).toBe("asleep");
    expect(workstationSignInState.qrText).toBeNull();
    expect(workstationSignInState.requestId).toBeNull();

    // And it stays quiet: no polls, no opens, however long it is left.
    const callsWhenAsleep = fetchCalls();

    for (let tick = 0; tick < 20; tick++) {
      await tickWorkstationSignIn(
        new Date(AFTER_WINDOW.getTime() + tick * SIGN_IN_POLL_INTERVAL_MS),
      );
    }

    expect(fetchCalls()).toBe(callsWhenAsleep);
    expect(node.openCount).toBe(openedWhileAwake);
  });

  it("wakes on a touch, opening a fresh request", async () => {
    await presentWorkstationSignIn(AWAKE);
    await tickWorkstationSignIn(AFTER_WINDOW);
    expect(workstationSignInState.status).toBe("asleep");

    node.expiresAt = new Date(AFTER_WINDOW.getTime() + 120_000).toISOString();
    await noteWorkstationPresence(AFTER_WINDOW);

    expect(workstationSignInState.status).toBe("presenting");
    expect(workstationSignInState.qrText).toContain("r=request-2");

    // And it polls again, because somebody is here.
    node.granted = true;
    const outcome = await tickWorkstationSignIn(
      new Date(AFTER_WINDOW.getTime() + SIGN_IN_POLL_INTERVAL_MS),
    );

    expect(outcome).toBe("signed_in");
  });

  it("extends the window on interaction rather than opening a request per touch", async () => {
    await presentWorkstationSignIn(AWAKE);
    const opened = node.openCount;

    // Somebody standing there, tapping around.
    for (let touch = 1; touch <= 5; touch++) {
      await noteWorkstationPresence(new Date(AWAKE.getTime() + touch * 10_000));
    }

    expect(node.openCount).toBe(opened);
    expect(workstationSignInState.status).toBe("presenting");

    // The window now runs from the last touch, not from the first present.
    await tickWorkstationSignIn(
      new Date(AWAKE.getTime() + 50_000 + SIGN_IN_AWAKE_WINDOW_MS - 1_000),
    );

    expect(workstationSignInState.status).toBe("presenting");
  });

  it("stops an unavailable presentation retrying forever", async () => {
    // The other loop that used to run all night: a node that cannot be reached
    // was retried every fifteen seconds indefinitely.
    node.reachable = false;
    await presentWorkstationSignIn(AWAKE);
    expect(workstationSignInState.status).toBe("unavailable");

    await tickWorkstationSignIn(AFTER_WINDOW);

    expect(workstationSignInState.status).toBe("asleep");
  });
});

describe("collection", () => {
  it("opens the session when the grant is collected", async () => {
    await presentWorkstationSignIn(NOW);
    node.granted = true;

    const outcome = await tickWorkstationSignIn(at(10));

    expect(outcome).toBe("signed_in");
    expect(workstationSessionState.status).toBe("active");
    expect(workstationSessionState.user?.name).toBe("Dana Reyes");

    // The presentation is over; nothing scannable stays behind a live session.
    expect(workstationSignInState.status).toBe("idle");
    expect(workstationSignInState.qrText).toBeNull();
  });

  it("holds the collected session key in memory and writes it nowhere", async () => {
    // M16.9's rule, unchanged by the path: "the session locks immediately if
    // the app restarts" is a property of where the key is kept.
    await presentWorkstationSignIn(NOW);
    node.granted = true;

    await tickWorkstationSignIn(at(10));

    expect(workstationSessionState.status).toBe("active");

    for (const value of storedValues()) {
      expect(value).not.toContain(SESSION_KEY);
      expect(value).not.toContain(PICKUP_SECRET);
    }
  });

  it("presents nothing while a session is live", async () => {
    await presentWorkstationSignIn(NOW);
    node.granted = true;
    await tickWorkstationSignIn(at(10));

    await presentWorkstationSignIn(NOW);

    expect(workstationSignInState.status).toBe("idle");
  });
});
