// When the device fetches its read set, what it refuses to serve, and what the
// banner says about it (M18.49; CLIENT-001; technical spec 9.3, 11A.4; UI
// contract 11.13, 16).
//
// No server runs here (CLIENT-024). `GET /api/offline-read-set` and `GET
// /api/me` are stubbed, and the triggers are exercised through the things that
// actually move — a session being installed, a context being switched, the node
// starting to answer — rather than by calling the refresh directly.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import { configureMeridianApi } from "@/api/meridianApi";
import mainTs from "@/main.ts?raw";
import {
  recordCentralReach,
  resetCentralReachability,
} from "@/offline/centralReachability";
import {
  recordNodeAnswered,
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";
import type { OfflineReadSetPayload } from "@/offline/offlineReadSet";
import {
  FIXTURE_EVENT_ID,
  offlineReadSetPayload,
} from "@/offline/offlineReadSetFixture";
import {
  installOfflineReadSetRefreshTriggers,
  offlineBannerState,
  offlineReadSetRefreshStatus,
  refreshOfflineReadSet,
  resetOfflineReadSetRefresh,
} from "@/offline/offlineReadSetRefresh";
import {
  clearOfflineReadSet,
  hydrateOfflineReadSet,
  offlineReadSetStore,
  readOfflineReadSetSection,
  resetOfflineReadSet,
  searchOfflineReadSet,
} from "@/offline/offlineReadSetRuntime";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  LOCAL_FIELD_OTHER_EVENT_ID,
  localFieldSessionDocument,
  switchableLocalFieldContext,
} from "@/session/localFieldSessionFixture";
import {
  registerSessionContextReset,
  switchSessionContext,
} from "@/session/sessionContext";

/** Every read-set request the client made, in order. */
let readSetRequests: string[] = [];

interface NodeStub {
  /** What `/api/offline-read-set` answers with. */
  readonly readSet?: () => Response | Promise<Response>;
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

/** The event a request named, or the fixture's own when it named none. */
function requestedEventId(path: string): string {
  return (
    new URL(path, "http://node.test").searchParams.get("event_id") ??
    FIXTURE_EVENT_ID
  );
}

/**
 * A node that answers both endpoints the triggers touch.
 *
 * `/api/me` resolves the session at the event the caller named, which is what
 * makes a context switch a context switch: the node is the one that decides
 * where the client lands, and the read set follows the answer.
 */
function stubNode(stub: NodeStub = {}): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL) => {
      const path = String(input);

      if (path.includes("/api/offline-read-set")) {
        readSetRequests.push(path);

        return stub.readSet?.() ?? jsonResponse(offlineReadSetPayload());
      }

      return jsonResponse(sessionAt(requestedEventId(path)));
    }),
  );
}

/** The switchable session, resolved at one of its two events. */
function sessionAt(eventId: string) {
  const switchable = switchableLocalFieldContext();

  return localFieldSessionDocument({
    ...switchable,
    context: { ...switchable.context, event_id: eventId },
  });
}

/** The set the specs hold, composed for the fixture's own event. */
function fixtureSet(
  overrides: Parameters<typeof offlineReadSetPayload>[0] = {},
): OfflineReadSetPayload {
  return offlineReadSetPayload(overrides);
}

let unregisterReset: () => void;

beforeEach(() => {
  readSetRequests = [];
  window.localStorage.clear();
  resetOfflineReadSet();
  resetOfflineReadSetRefresh();
  clearClientSession();
  resetNodeReachability();
  resetCentralReachability();
  unregisterReset = registerSessionContextReset(() => {
    clearOfflineReadSet();
  });
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  unregisterReset();
  resetOfflineReadSet();
  resetOfflineReadSetRefresh();
  clearClientSession();
  resetNodeReachability();
  resetCentralReachability();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("fetching the set proactively", () => {
  it("fetches it on sign-in rather than waiting for a surface to ask", async () => {
    /*
     * The difference this task exists for. The read cache it replaces stored
     * what its user happened to open while they had a signal, so a volunteer who
     * signed in at the gate and walked into a field held whatever they had
     * browsed. This holds what they are authorized to hold because they signed
     * in.
     */
    stubNode();

    const stop = installOfflineReadSetRefreshTriggers();

    expect(readSetRequests).toHaveLength(0);

    installClientSession(localFieldSessionDocument(), "network");
    await nextTick();
    await refreshOfflineReadSet("requested");

    expect(readSetRequests[0]).toBe(
      `http://node.test/api/offline-read-set?event_id=${FIXTURE_EVENT_ID}`,
    );
    expect(offlineReadSetRefreshStatus.value.trigger).not.toBeNull();
    expect(offlineReadSetStore.held()).not.toBeNull();

    stop();
  });

  it("fetches the new context's set when the client switches", async () => {
    // The set for the event being left is dropped on the switch (CLIENT-014),
    // and there is no version of it that belongs to the event being entered. A
    // client that switched and did not refetch would hold nothing at all.
    stubNode();

    installClientSession(sessionAt(FIXTURE_EVENT_ID), "network");

    const stop = installOfflineReadSetRefreshTriggers();

    await refreshOfflineReadSet("requested");
    readSetRequests = [];

    expect(await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID)).toBe(
      "switched",
    );
    await nextTick();
    await refreshOfflineReadSet("requested");

    expect(readSetRequests[0]).toBe(
      `http://node.test/api/offline-read-set?event_id=${LOCAL_FIELD_OTHER_EVENT_ID}`,
    );
    expect(offlineReadSetRefreshStatus.value.trigger).toBe("requested");

    stop();
  });

  it("fetches it when the node starts answering again", async () => {
    /*
     * The read-side half of what 11A.4 already requires of permissions on
     * reconnect: the set is composed from live grants, so the first thing a
     * reconnecting device should find out is what it may still hold.
     */
    stubNode();
    installClientSession(localFieldSessionDocument(), "network");
    recordNodeUnreachable();

    const stop = installOfflineReadSetRefreshTriggers();

    await nextTick();
    await refreshOfflineReadSet("requested");
    readSetRequests = [];

    recordNodeAnswered();
    await nextTick();
    await refreshOfflineReadSet("requested");

    expect(readSetRequests.length).toBeGreaterThan(0);

    stop();
  });

  it("fetches it when the node comes back with central still unreachable", async () => {
    /*
     * The trigger is regaining the *node*, not regaining `online` (M18.52). The
     * set is composed by the node this device is pointed at, so a device that
     * reaches its on-site node during an internet outage has everything it
     * needs to be handed one — and a trigger written against the banner state
     * would have gone quiet the moment the second tier started reporting
     * `central_unreachable`.
     */
    stubNode();
    installClientSession(localFieldSessionDocument(), "network");
    recordCentralReach("unreachable");
    recordNodeUnreachable();

    const stop = installOfflineReadSetRefreshTriggers();

    await nextTick();
    await refreshOfflineReadSet("requested");
    readSetRequests = [];

    recordNodeAnswered();
    await nextTick();
    await refreshOfflineReadSet("requested");

    expect(readSetRequests.length).toBeGreaterThan(0);

    stop();
  });

  it("asks nothing of the node for a client with no session", async () => {
    // Nothing to compose a set for, and a signed-out client must not be given a
    // reason to call the node on every network change.
    stubNode();

    const stop = installOfflineReadSetRefreshTriggers();

    recordNodeUnreachable();
    await nextTick();
    recordNodeAnswered();
    await nextTick();

    expect(await refreshOfflineReadSet("requested")).toBe("skipped");
    expect(readSetRequests).toHaveLength(0);

    stop();
  });

  it("composes against the context it is in when it runs, not the one it was asked for", async () => {
    /*
     * Refreshes are serialized and the context is read at execution. Without
     * that, a switch during an in-flight pull lands the previous event's set on
     * a device that had just dropped it, and the store cannot tell the
     * difference — from its side a late arrival and a fresh answer are the same
     * call.
     */
    let release = (): void => undefined;
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    let answered = 0;

    stubNode({
      readSet: async () => {
        answered += 1;

        if (answered === 1) {
          await held;
        }

        return jsonResponse(fixtureSet());
      },
    });

    installClientSession(sessionAt(FIXTURE_EVENT_ID), "network");

    const first = refreshOfflineReadSet("requested");

    // Let the first pull read its context and reach the node, where it waits.
    while (readSetRequests.length === 0) {
      await Promise.resolve();
    }

    // The client moves while that answer is still on the wire.
    installClientSession(sessionAt(LOCAL_FIELD_OTHER_EVENT_ID), "network");

    const second = refreshOfflineReadSet("requested");

    release();
    await first;
    await second;

    expect(readSetRequests[0]).toContain(FIXTURE_EVENT_ID);
    expect(readSetRequests[1]).toContain(LOCAL_FIELD_OTHER_EVENT_ID);
    // And the event that was left does not leave its rows behind: the late
    // answer was dropped, and the set the device holds is the one it is in.
    expect(offlineReadSetStore.held()?.context.eventId).toBe(
      LOCAL_FIELD_OTHER_EVENT_ID,
    );
  });

  it("waits for the durable copy before asking, so the boot refresh is conditional", async () => {
    /*
     * The sign-in trigger fires at boot against the cached session, which is
     * exactly when the durable copy is still being read. A refresh that overtook
     * it would hold no version, send no `If-None-Match`, and transfer the whole
     * set on every start — on precisely the connection this endpoint was made
     * conditional for. This asserts the ordering the header depends on; the
     * header itself is asserted against a real node, where a reload of a device
     * already holding the set answers 304.
     */
    const order: string[] = [];

    stubNode({
      readSet: () => {
        order.push("asked");

        return jsonResponse(fixtureSet());
      },
    });
    installClientSession(localFieldSessionDocument(), "network");

    const hydrating = hydrateOfflineReadSet().then(() => {
      order.push("hydrated");
    });
    const refreshing = refreshOfflineReadSet("sign_in");

    await hydrating;
    await refreshing;

    expect(order).toEqual(["hydrated", "asked"]);
  });

  it("is installed by the application at boot", () => {
    /*
     * The tests above prove the triggers fire; this proves the application
     * installs them. They live in `main.ts` rather than in the shell because a
     * device whose set went stale while nobody was looking at a screen still
     * needs it fetched.
     */
    expect(mainTs).toContain("installOfflineReadSetRefreshTriggers()");
  });
});

describe("refusing a set past its event window", () => {
  async function holdASetUsableUntil(usableUntil: string): Promise<void> {
    stubNode({
      readSet: () =>
        jsonResponse(fixtureSet({ readiness: { usable_until: usableUntil } })),
    });

    installClientSession(localFieldSessionDocument(), "network");

    expect(await refreshOfflineReadSet("requested")).toBe("refreshed");
  }

  it("serves a set inside its window", async () => {
    await holdASetUsableUntil("2999-01-01T00:00:00+00:00");

    expect(readOfflineReadSetSection("staff")).toHaveLength(1);
    expect(searchOfflineReadSet("staff", "dana", ["handle"])).toHaveLength(1);
  });

  it("serves one past its window for six weeks from the refresh, then refuses it", async () => {
    /*
     * The window ending starts the six-week fallback rather than emptying the
     * device (CLIENT-008A): the set was refreshed a moment ago, so a crew
     * packing down after an event keeps its data. Beyond the six weeks the set
     * is held and not served, which is the original distinction — the device
     * keeps the record, because throwing it away would leave a reconnecting
     * device re-transferring a set it may still be told is current, and every
     * read comes back empty until a refresh replaces it (technical spec 11A.4).
     */
    await holdASetUsableUntil("2000-01-01T00:00:00+00:00");

    expect(readOfflineReadSetSection("staff")).toHaveLength(1);

    const beyondFallback = new Date(Date.now() + 7 * 7 * 24 * 60 * 60 * 1000);

    expect(offlineReadSetStore.section("staff")).toHaveLength(1);
    expect(readOfflineReadSetSection("staff", beyondFallback)).toEqual([]);
    expect(
      searchOfflineReadSet("staff", "dana", ["handle"], beyondFallback),
    ).toEqual([]);
  });

  it("serves the node's own answer for a caller with no event resolved", async () => {
    // A staff member with no event still holds their own record and their own
    // documents. It is the copy read off disk tomorrow that has nothing to say
    // how old it is, not the answer that just arrived.
    stubNode({
      readSet: () =>
        jsonResponse(
          fixtureSet({
            readiness: { context_event_id: null, usable_until: null },
          }),
        ),
    });
    installClientSession(localFieldSessionDocument(), "network");

    await refreshOfflineReadSet("requested");

    expect(readOfflineReadSetSection("staff")).toHaveLength(1);
  });
});

describe("what the banner says about a refresh", () => {
  it("says sync failed when the node answered and the answer was unusable", async () => {
    // The node was reached and what came back is not a set this build can hold.
    // It does not clear up on its own, which is what separates it from being
    // offline.
    stubNode({ readSet: () => jsonResponse({ sections: {} }) });
    installClientSession(localFieldSessionDocument(), "network");

    expect(await refreshOfflineReadSet("requested")).toBe("unusable");
    expect(offlineBannerState("online")).toBe("sync_failed");
    expect(offlineBannerState("offline_usable")).toBe("sync_failed");
  });

  it("says sync failed when the node refused the context", async () => {
    stubNode({
      readSet: () =>
        jsonResponse(
          { message: "This node is locked to another event." },
          409,
        ),
    });
    installClientSession(localFieldSessionDocument(), "network");

    expect(await refreshOfflineReadSet("requested")).toBe("refused");
    expect(offlineBannerState("online")).toBe("sync_failed");
    expect(offlineReadSetRefreshStatus.value.detail).toBe(
      "This node is locked to another event.",
    );
  });

  it("says queued when it could not reach the node and holds nothing to work from", async () => {
    stubNode({
      readSet: () => {
        throw new TypeError("Failed to fetch");
      },
    });
    installClientSession(localFieldSessionDocument(), "network");

    expect(await refreshOfflineReadSet("requested")).toBe("unreachable");
    expect(offlineBannerState("offline_usable")).toBe("sync_queued");
  });

  it("stays quiet when it could not reach the node and holds a set it may serve", async () => {
    /*
     * Contract 16.2: do not interrupt routine field work with sync noise. A
     * device working from its authorized set with no signal is doing exactly
     * what the set is for, and the connectivity state already says why nothing
     * is refreshing.
     */
    stubNode();
    installClientSession(localFieldSessionDocument(), "network");
    await refreshOfflineReadSet("requested");

    stubNode({
      readSet: () => {
        throw new TypeError("Failed to fetch");
      },
    });

    expect(await refreshOfflineReadSet("requested")).toBe("unreachable");
    expect(offlineBannerState("offline_usable")).toBe("offline_usable");
    expect(offlineBannerState("online")).toBe("online");
  });

  it("says queued for a device holding a set past its event window", async () => {
    /*
     * It holds rows it will not serve and it cannot reach anybody to replace
     * them, which is a device waiting on connectivity rather than one working.
     * The device's trust has expired, which is what closes the six-week
     * fallback (CLIENT-008A) — a trusted device refreshed this recently would
     * still be serving the set, and the banner would rightly stay quiet.
     */
    stubNode({
      readSet: () =>
        jsonResponse(
          fixtureSet({ readiness: { usable_until: "2000-01-01T00:00:00+00:00" } }),
        ),
    });
    installClientSession(
      localFieldSessionDocument({
        device: {
          id: "device-under-test",
          label: null,
          trusted: false,
          trust_state: "expired",
          trusted_until: "2000-02-01T00:00:00+00:00",
        },
      }),
      "network",
    );
    await refreshOfflineReadSet("requested");

    stubNode({
      readSet: () => {
        throw new TypeError("Failed to fetch");
      },
    });
    await refreshOfflineReadSet("requested");

    expect(offlineBannerState("offline_usable")).toBe("sync_queued");
  });
});
