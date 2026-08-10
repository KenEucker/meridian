import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import { clearClientSession } from "@/session/clientSession";
import { localFieldSessionDocument } from "@/session/localFieldSessionFixture";
import { configureSharedWorkstationId } from "@/session/workstationIdentity";
import {
  presentWorkstationReauth,
  resetWorkstationReauth,
  tickWorkstationReauth,
  workstationReauthState,
} from "@/session/workstationReauthSignIn";
import {
  enterWorkstationLoginCode,
  resetWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * The Kiosk's half of re-authentication by scan (M18.62; AUTH-036; technical
 * spec 13.4).
 *
 * What is asserted: a workstation holding a live session presents a
 * re-authentication request as a QR beside the typed field; a collected grant
 * stamps `reauthenticated_at` on the same session without a new key changing
 * hands; an unreachable node falls back to the typed field with a stated
 * reason; and a locked workstation presents nothing.
 */

const WORKSTATION_ID = "workstation-gate-a";
const SESSION_KEY = "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh";
const PICKUP_SECRET = "reauth-pickup-secret-only-the-workstation-holds";

const node = {
  granted: false,
  reachableForReauth: true,
  reauthenticatedAt: "2027-06-01T12:03:00+00:00",
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function sessionDocument(reauthenticatedAt: string | null) {
  return {
    session: {
      id: "session-1",
      started_at: "2027-06-01T12:00:00+00:00",
      last_activity_at: "2027-06-01T12:03:00+00:00",
      expires_at: "2027-06-01T12:08:00+00:00",
      inactivity_timeout_seconds: 300,
      reauthenticated_at: reauthenticatedAt,
    },
    user: { id: "user-1", name: "Dana Reyes" },
    shared_workstation: {
      id: WORKSTATION_ID,
      name: "Gate A Workstation",
      organization_id: "org-1",
      department_id: null,
    },
    event_id: "event-1",
  };
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = new URL(String(input)).pathname;
      const method = (init?.method ?? "GET").toUpperCase();

      if (path === "/api/me") {
        return json(localFieldSessionDocument());
      }

      if (path === "/api/auth/shared-workstation-session" && method === "POST") {
        return json({ session_key: SESSION_KEY, ...sessionDocument(null) }, 201);
      }

      if (path.endsWith("/reauthentication-requests") && method === "POST") {
        if (!node.reachableForReauth) {
          throw new TypeError("fetch failed");
        }

        return json(
          {
            sign_in_request: {
              id: "reauth-request-1",
              purpose: "reauthentication",
              expires_at: "2027-06-01T12:02:00+00:00",
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

      if (path.endsWith("/collect") && method === "POST") {
        if (!node.granted) {
          return json({ status: "pending" });
        }

        return json({ status: "collected", ...sessionDocument(node.reauthenticatedAt) });
      }

      return json({ message: "Unexpected request in test." }, 500);
    }),
  );
}

beforeEach(async () => {
  node.granted = false;
  node.reachableForReauth = true;

  window.localStorage.clear();
  resetWorkstationReauth();
  resetWorkstationSession();
  clearClientSession();
  configureSharedWorkstationId(WORKSTATION_ID);
  stubNode();
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });

  await enterWorkstationLoginCode({
    sharedWorkstationId: WORKSTATION_ID,
    code: "K3M7PQRS",
  });
});

afterEach(() => {
  configureSharedWorkstationId(null);
  resetWorkstationReauth();
  resetWorkstationSession();
  clearClientSession();
  configureMeridianApi(null);
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("presenting a re-authentication request", () => {
  it("presents the request bound to the live session as a QR", async () => {
    await presentWorkstationReauth();

    expect(workstationReauthState.status).toBe("presenting");
    expect(workstationReauthState.qrText).toContain("r=reauth-request-1");
    expect(workstationReauthState.qrText).toContain(`w=${WORKSTATION_ID}`);
    expect(workstationReauthState.qrText).not.toContain(PICKUP_SECRET);
  });

  it("presents nothing on a locked workstation", async () => {
    resetWorkstationSession();

    await presentWorkstationReauth();

    expect(workstationReauthState.status).toBe("idle");
    expect(workstationReauthState.qrText).toBeNull();
  });

  it("falls back to the typed field with a stated reason when the node is unreachable", async () => {
    node.reachableForReauth = false;

    await presentWorkstationReauth();

    expect(workstationReauthState.status).toBe("unavailable");
    expect(workstationReauthState.unavailableReason).toContain("could not reach the node");
    expect(workstationReauthState.unavailableReason).toContain("Enter a login code instead");
  });
});

describe("collection", () => {
  it("waits while nobody has granted", async () => {
    await presentWorkstationReauth();

    const outcome = await tickWorkstationReauth(new Date("2027-06-01T12:00:10+00:00"));

    expect(outcome).toBe("waiting");
    expect(workstationReauthState.status).toBe("presenting");
    expect(workstationSessionState.reauthenticatedAt).toBeNull();
  });

  it("applies a collected grant to the live session without a new key", async () => {
    await presentWorkstationReauth();
    node.granted = true;

    const outcome = await tickWorkstationReauth(new Date("2027-06-01T12:00:10+00:00"));

    expect(outcome).toBe("confirmed");

    // The same session, confirmed: same user, still active, stamped exactly
    // as a typed code would have stamped it (M18.62).
    expect(workstationSessionState.status).toBe("active");
    expect(workstationSessionState.user?.name).toBe("Dana Reyes");
    expect(workstationSessionState.reauthenticatedAt).toBe(node.reauthenticatedAt);

    // The presentation is over and the secret is gone with it.
    expect(workstationReauthState.status).toBe("idle");
    expect(workstationReauthState.qrText).toBeNull();
  });

  it("replaces an expired request rather than leaving a stale square rendered", async () => {
    await presentWorkstationReauth();

    const outcome = await tickWorkstationReauth(new Date("2027-06-01T12:02:01+00:00"));

    expect(outcome).toBe("waiting");
    expect(workstationReauthState.status).toBe("presenting");
    expect(workstationReauthState.requestId).toBe("reauth-request-1");
  });
});
