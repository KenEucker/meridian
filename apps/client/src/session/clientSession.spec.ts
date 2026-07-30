import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  bootClientSessionFromCache,
  clearClientSession,
  clientSessionState,
  loadClientSession,
  refreshClientSession,
  refreshClientSessionOnReconnect,
  sessionAccessGranted,
  sessionCapabilities,
  sessionHasCapability,
} from "@/session/clientSession";
import { readCachedSession, writeCachedSession } from "@/session/sessionCache";
import type { SessionDocument } from "@/session/sessionDocument";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

const insideWindow = new Date("2026-09-11T18:35:00+00:00");
const afterWindow = new Date("2026-09-16T18:35:00+00:00");

function respondWith(document: SessionDocument | Record<string, unknown>) {
  // Typed against the platform signature so the recorded calls carry the
  // requested URL: which context the client asked its roles to resolve at is
  // part of the behavior under test.
  return vi.fn(
    async (_input: RequestInfo | URL, _init?: RequestInit) =>
      new Response(JSON.stringify(document), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
  );
}

function refuse(status: number) {
  return vi.fn(
    async () =>
      new Response(JSON.stringify({ message: "Unauthenticated." }), {
        status,
        headers: { "content-type": "application/json" },
      }),
  );
}

function unreachable() {
  return vi.fn(async () => {
    throw new TypeError("Failed to fetch");
  });
}

describe("client session", () => {
  beforeEach(() => {
    window.localStorage.clear();
    clearClientSession();
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });
  });

  afterEach(() => {
    configureMeridianApi(null);
    clearClientSession();
    window.localStorage.clear();
    vi.unstubAllGlobals();
  });

  it("holds nothing before it has been established", () => {
    expect(clientSessionState.status).toBe("unresolved");
    expect(sessionAccessGranted.value).toBe(false);
    expect(sessionCapabilities()).toEqual([]);
  });

  it("boots from the cached session when the node is unreachable", async () => {
    writeCachedSession(fixtureSessionDocument(), "2026-09-11T18:30:05+00:00");
    vi.stubGlobal("fetch", unreachable());

    const outcome = await loadClientSession({ now: insideWindow });

    expect(outcome).toBe("unreachable");
    expect(clientSessionState.status).toBe("cached");
    expect(sessionAccessGranted.value).toBe(true);
    expect(sessionHasCapability("department.manage")).toBe(true);
    // The last time the node answered, not the last time this client asked.
    expect(clientSessionState.refreshedAt).toBe("2026-09-11T18:30:00+00:00");
    expect(clientSessionState.refreshFailedAt).toBe(insideWindow.toISOString());
  });

  it("grants nothing when the node is unreachable and nothing was cached", async () => {
    vi.stubGlobal("fetch", unreachable());

    expect(await loadClientSession({ now: insideWindow })).toBe("unreachable");
    expect(clientSessionState.status).toBe("unresolved");
    expect(sessionAccessGranted.value).toBe(false);
  });

  it("refuses access once the cached event window has ended", async () => {
    writeCachedSession(fixtureSessionDocument());
    vi.stubGlobal("fetch", unreachable());

    await loadClientSession({ now: afterWindow });

    expect(clientSessionState.status).toBe("expired");
    expect(clientSessionState.refreshReason).toBe("event_window_ended");
    expect(sessionAccessGranted.value).toBe(false);
    // The document is still held so the surface can say which event ended, but
    // no capability is readable from it.
    expect(clientSessionState.document).not.toBeNull();
    expect(sessionCapabilities()).toEqual([]);
    expect(sessionHasCapability("department.manage")).toBe(false);
  });

  it("loses access where it stands when the window ends under a running client", async () => {
    writeCachedSession(fixtureSessionDocument());
    vi.stubGlobal("fetch", unreachable());

    await loadClientSession({ now: insideWindow });

    expect(clientSessionState.status).toBe("cached");

    // The device is still offline hours later, and the event has closed since.
    await refreshClientSession({ now: afterWindow });

    expect(clientSessionState.status).toBe("expired");
    expect(sessionAccessGranted.value).toBe(false);
  });

  it("drops a permission the node has taken away, on the refresh rather than the next login", async () => {
    writeCachedSession(fixtureSessionDocument());

    expect(bootClientSessionFromCache(insideWindow)).toBe(true);
    expect(sessionHasCapability("department.manage")).toBe(true);

    const reduced = fixtureSessionDocument({
      roles: [],
      capabilities: ["shift.assign"],
      refreshed_at: "2026-09-11T18:35:00+00:00",
    });

    vi.stubGlobal("fetch", respondWith(reduced));

    expect(await refreshClientSession({ now: insideWindow })).toBe("refreshed");
    expect(clientSessionState.status).toBe("live");
    expect(sessionCapabilities()).toEqual(["shift.assign"]);
    expect(sessionHasCapability("department.manage")).toBe(false);
    // And it is gone from the durable copy too, so a restart cannot resurrect
    // the authority this answer took away.
    expect(readCachedSession()?.document.capabilities).toEqual([
      "shift.assign",
    ]);
    expect(clientSessionState.refreshedAt).toBe("2026-09-11T18:35:00+00:00");
  });

  it("stores the node's answer for the next cold boot", async () => {
    vi.stubGlobal("fetch", respondWith(fixtureSessionDocument()));

    await refreshClientSession({ now: insideWindow });

    expect(readCachedSession()?.cachedAt).toBe(insideWindow.toISOString());
    expect(clientSessionState.refreshFailedAt).toBeNull();
  });

  it("resolves roles at the event it already holds", async () => {
    const fetchMock = respondWith(fixtureSessionDocument());

    writeCachedSession(fixtureSessionDocument());
    bootClientSessionFromCache(insideWindow);
    vi.stubGlobal("fetch", fetchMock);

    await refreshClientSession({ now: insideWindow });

    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      "http://node.test/api/me?event_id=event-decompression-2026",
    );
  });

  it("asks for whatever context the node resolves when it holds none", async () => {
    const fetchMock = respondWith(fixtureSessionDocument());
    vi.stubGlobal("fetch", fetchMock);

    await refreshClientSession({ now: insideWindow });

    expect(fetchMock.mock.calls[0]?.[0]).toBe("http://node.test/api/me");
  });

  it("drops the session when the node refuses the credential", async () => {
    writeCachedSession(fixtureSessionDocument());
    bootClientSessionFromCache(insideWindow);
    vi.stubGlobal("fetch", refuse(401));

    expect(await refreshClientSession({ now: insideWindow })).toBe(
      "unauthenticated",
    );
    expect(clientSessionState.status).toBe("unresolved");
    expect(clientSessionState.document).toBeNull();
    // A revoked token or device must not keep working from what it was once
    // issued, so the durable copy goes with it.
    expect(readCachedSession()).toBeNull();
  });

  it("keeps the cached session when the node answers something it cannot read", async () => {
    writeCachedSession(fixtureSessionDocument());
    bootClientSessionFromCache(insideWindow);
    vi.stubGlobal("fetch", respondWith({ user: { id: "user-dana" } }));

    expect(await refreshClientSession({ now: insideWindow })).toBe("unusable");
    expect(clientSessionState.status).toBe("cached");
    expect(sessionHasCapability("department.manage")).toBe(true);
    expect(readCachedSession()?.document.capabilities).toEqual([
      "department.manage",
      "shift.assign",
    ]);
  });

  it("refreshes on regaining connectivity", async () => {
    writeCachedSession(fixtureSessionDocument());
    bootClientSessionFromCache(insideWindow);

    const reduced = fixtureSessionDocument({
      roles: [],
      capabilities: [],
    });

    vi.stubGlobal("fetch", respondWith(reduced));

    expect(
      await refreshClientSessionOnReconnect("online", "offline_usable"),
    ).toBe("refreshed");
    expect(sessionCapabilities()).toEqual([]);
  });

  it("does not ask again while it is already online", async () => {
    const fetchMock = respondWith(fixtureSessionDocument());

    writeCachedSession(fixtureSessionDocument());
    bootClientSessionFromCache(insideWindow);
    vi.stubGlobal("fetch", fetchMock);

    expect(await refreshClientSessionOnReconnect("online", "online")).toBe(
      "skipped",
    );
    expect(await refreshClientSessionOnReconnect("online", undefined)).toBe(
      "skipped",
    );
    expect(await refreshClientSessionOnReconnect("offline_usable", "online")).toBe(
      "skipped",
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("does not call the node on connectivity changes when no session is held", async () => {
    const fetchMock = respondWith(fixtureSessionDocument());
    vi.stubGlobal("fetch", fetchMock);

    expect(
      await refreshClientSessionOnReconnect("online", "offline_usable"),
    ).toBe("skipped");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
