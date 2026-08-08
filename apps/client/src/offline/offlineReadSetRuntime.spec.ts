// Filling the offline read set, and losing it (M18.48; ADR-0003; CLIENT-021,
// CLIENT-022; technical spec 9.3, 11A.7, 13.3).
//
// No server runs here (CLIENT-024). `GET /api/offline-read-set` is stubbed, so
// the request the client makes — the conditional header above all — is itself
// part of what is asserted.
//
// The three drops are exercised through the paths that perform them rather than
// by calling the store's own `clear`: a sign-out, an event switch, and a
// shared-workstation session end each run the reset registry, and what this
// asserts is that the read set is on it.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import mainTs from "@/main.ts?raw";
import { configureMeridianApi } from "@/api/meridianApi";
import type { OfflineReadSetPayload } from "@/offline/offlineReadSet";
import {
  FIXTURE_EVENT_ID,
  FIXTURE_ORGANIZATION_ID,
  offlineReadSetPayload,
} from "@/offline/offlineReadSetFixture";
import {
  clearOfflineReadSet,
  offlineReadSetRevision,
  offlineReadSetStore,
  pullOfflineReadSet,
  resetOfflineReadSet,
} from "@/offline/offlineReadSetRuntime";
import { signOut } from "@/session/apiLogin";
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
import {
  endWorkstationSession,
  resetWorkstationSession,
} from "@/session/workstationSession";

const CONTEXT = {
  organizationId: FIXTURE_ORGANIZATION_ID,
  eventId: FIXTURE_EVENT_ID,
};

interface StubbedRequest {
  readonly path: string;
  readonly ifNoneMatch: string | null;
}

let requests: StubbedRequest[] = [];

function record(input: RequestInfo | URL, init?: RequestInit): void {
  const headers = new Headers(init?.headers);

  requests.push({
    path: String(input),
    ifNoneMatch: headers.get("If-None-Match"),
  });
}

function respondWithSet(set: OfflineReadSetPayload) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    record(input, init);

    return new Response(JSON.stringify(set), {
      status: 200,
      headers: { "content-type": "application/json", ETag: `"${set.version}"` },
    });
  });
}

function respondWith(status: number, body: unknown = null) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    record(input, init);

    return new Response(body === null ? null : JSON.stringify(body), {
      status,
      headers: body === null ? {} : { "content-type": "application/json" },
    });
  });
}

function unreachable() {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    record(input, init);

    throw new TypeError("Failed to fetch");
  });
}

/** What `main.ts` registers at boot, registered here for the same reason. */
let unregisterReset: () => void;

beforeEach(() => {
  requests = [];
  window.localStorage.clear();
  resetOfflineReadSet();
  clearClientSession();
  resetWorkstationSession();
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
  clearClientSession();
  resetWorkstationSession();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("pulling the set", () => {
  it("stores what the node composed, for the context it was composed for", async () => {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));

    expect(await pullOfflineReadSet(CONTEXT)).toEqual({
      outcome: "refreshed",
      detail: null,
    });
    expect(offlineReadSetStore.version()).toBe("version-1");
    expect(offlineReadSetStore.held()?.context).toEqual(CONTEXT);
    expect(offlineReadSetRevision.value).toBeGreaterThan(0);
  });

  it("asks for the context event rather than whatever the node would resolve", async () => {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));

    await pullOfflineReadSet(CONTEXT);

    expect(requests[0]?.path).toBe(
      `http://node.test/api/offline-read-set?event_id=${FIXTURE_EVENT_ID}`,
    );
  });

  it("asks the node to resolve the context when the client holds none", async () => {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));

    await pullOfflineReadSet({ organizationId: null, eventId: null });

    expect(requests[0]?.path).toBe("http://node.test/api/offline-read-set");
  });

  it("sends the version it holds and keeps the set when nothing has changed", async () => {
    // The device asking for this is the one on the weak connection at the
    // event, so the refresh that finds nothing new costs a header rather than a
    // payload.
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));
    await pullOfflineReadSet(CONTEXT);

    vi.stubGlobal("fetch", respondWith(304));

    expect(await pullOfflineReadSet(CONTEXT)).toEqual({
      outcome: "unchanged",
      detail: null,
    });
    expect(requests[1]?.ifNoneMatch).toBe('"version-1"');
    expect(offlineReadSetStore.section("staff")).toHaveLength(1);
  });

  it("sends no conditional header when it holds nothing", async () => {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));

    await pullOfflineReadSet(CONTEXT);

    expect(requests[0]?.ifNoneMatch).toBeNull();
  });

  it("replaces the set whole when the node composes a different one", async () => {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));
    await pullOfflineReadSet(CONTEXT);

    vi.stubGlobal(
      "fetch",
      respondWithSet(
        offlineReadSetPayload({
          version: "version-2",
          sections: { staff: [{ id: "staff-self" }] },
        }),
      ),
    );

    expect((await pullOfflineReadSet(CONTEXT)).outcome).toBe("refreshed");
    expect(offlineReadSetStore.version()).toBe("version-2");
    // The grant behind `shifts` is gone as far as this device can tell, so the
    // rows go with it (CLIENT-022).
    expect(offlineReadSetStore.carries("shifts")).toBe(false);
  });

  it("keeps what it holds when the node cannot be reached", async () => {
    // "Offline data may be stale, but stale authorized data is better than no
    // data" (technical spec 9.3).
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));
    await pullOfflineReadSet(CONTEXT);

    vi.stubGlobal("fetch", unreachable());

    expect(await pullOfflineReadSet(CONTEXT)).toEqual({
      outcome: "unreachable",
      detail: null,
    });
    expect(offlineReadSetStore.version()).toBe("version-1");
  });

  it("drops the set when the node refuses the credential", async () => {
    /*
     * A refused credential is not an unreachable node. The node was reached and
     * said this token is no good, so holding records composed from a grant it
     * has withdrawn is exactly what a device-local copy must never do
     * (CLIENT-006, CLIENT-022, AUTH-023).
     */
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));
    await pullOfflineReadSet(CONTEXT);

    vi.stubGlobal("fetch", respondWith(401, { message: "Unauthenticated." }));

    expect(await pullOfflineReadSet(CONTEXT)).toEqual({
      outcome: "unauthenticated",
      detail: "Unauthenticated.",
    });
    expect(offlineReadSetStore.held()).toBeNull();
  });

  it("keeps the set when the node refuses the requested event", async () => {
    // A context this caller cannot resolve on this node says nothing about the
    // context they are actually standing in, and taking their offline data away
    // over it would be a refusal answering a question it was not asked.
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));
    await pullOfflineReadSet(CONTEXT);

    vi.stubGlobal(
      "fetch",
      respondWith(409, { message: "This node is locked to another event." }),
    );

    expect(await pullOfflineReadSet(CONTEXT)).toEqual({
      outcome: "refused",
      detail: "This node is locked to another event.",
    });
    expect(offlineReadSetStore.version()).toBe("version-1");
  });

  it("keeps the set when the node answers with something that is not one", async () => {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));
    await pullOfflineReadSet(CONTEXT);

    vi.stubGlobal("fetch", respondWith(200, { sections: {} }));

    expect((await pullOfflineReadSet(CONTEXT)).outcome).toBe("unusable");
    expect(offlineReadSetStore.version()).toBe("version-1");
  });
});

describe("dropping the set with the session", () => {
  async function holdASet(): Promise<void> {
    vi.stubGlobal("fetch", respondWithSet(offlineReadSetPayload()));

    await pullOfflineReadSet(CONTEXT);

    expect(offlineReadSetStore.held()).not.toBeNull();
  }

  it("drops it on sign-out", async () => {
    await holdASet();

    await signOut();

    expect(offlineReadSetStore.held()).toBeNull();
    expect(offlineReadSetStore.section("staff")).toEqual([]);
  });

  it("drops it on a context switch", async () => {
    // CLIENT-014. There is no version of a department roster or a planning
    // aggregate that belongs to the context being entered; the set for the new
    // one is pulled by M18.49, not carried over from here.
    await holdASet();

    installClientSession(
      localFieldSessionDocument(switchableLocalFieldContext()),
      "network",
    );
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify(
              localFieldSessionDocument(switchableLocalFieldContext()),
            ),
            { status: 200, headers: { "content-type": "application/json" } },
          ),
      ),
    );

    expect(await switchSessionContext(LOCAL_FIELD_OTHER_EVENT_ID)).toBe(
      "switched",
    );
    expect(offlineReadSetStore.held()).toBeNull();
  });

  it("drops it when a shared-workstation session ends", async () => {
    // Technical spec 13.3, and the reason the drop is synchronous: the next
    // person to stand at this machine is somebody else.
    await holdASet();

    await endWorkstationSession("signed_out");

    expect(offlineReadSetStore.held()).toBeNull();
  });

  it("is registered by the application at boot", () => {
    /*
     * The tests above prove the registry drops the set; this proves the
     * application puts it on the registry. `main.ts` is where every
     * context-scoped drop is registered, eagerly, so a switch cannot miss a
     * registration that had not been imported yet — and a store that nothing
     * registers would pass every test above and still outlive a sign-out on a
     * real device.
     */
    expect(mainTs).toContain("clearOfflineReadSet");
    expect(mainTs).toMatch(
      /registerSessionContextReset\([\s\S]*clearOfflineReadSet\(\)/,
    );
  });
});
