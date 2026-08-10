import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import { resetCentralReachability } from "@/offline/centralReachability";
import {
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";
import { ConnectedOnlyCommandError } from "@/outbox/submitCommand";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  HIDEABLE_PAGES,
  hiddenPageKeys,
  hiddenRouteNames,
  pageHidden,
  resetHiddenPageAnswers,
  setPageHidden,
  visibleLinks,
} from "@/session/hiddenPages";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

/*
 * Pages the reader has put away (M18.69).
 *
 * No server runs here (CLIENT-024). The write is connected-only, so it is
 * exercised against a stubbed `fetch` and against a device with no node.
 *
 * The property worth stating up front: nothing in this module decides
 * authority. Every case below is about what somebody chose to look at, and the
 * routes it names stay reachable — hiding is subtraction from a list that the
 * capability checks already built.
 */

function setDeviceOnLine(onLine: boolean): void {
  Object.defineProperty(globalThis.navigator, "onLine", {
    configurable: true,
    get: () => onLine,
  });
  globalThis.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

function respondWith(hiddenPages: readonly string[]): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(
    async () =>
      new Response(JSON.stringify({ hidden_pages: hiddenPages }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
  );

  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

beforeEach(() => {
  configureMeridianApi({
    baseUrl: "http://127.0.0.1:8000",
    bearerToken: "device-token",
  });
  resetNodeReachability();
  resetCentralReachability();
  resetHiddenPageAnswers();
  setDeviceOnLine(true);
});

afterEach(() => {
  clearClientSession();
  resetHiddenPageAnswers();
  resetNodeReachability();
  resetCentralReachability();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("what the session says is hidden", () => {
  it("reads the node's answer off the session document", () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );

    expect(pageHidden("dashboard")).toBe(true);
    expect([...hiddenRouteNames.value].sort()).toEqual([
      "events.departments.show",
      "ims.dashboard",
      "organizer.dashboard",
      "staff.dashboard",
    ]);
  });

  it("takes an empty list as an answer rather than as a missing one", () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: [] } }),
      "network",
    );

    expect(pageHidden("dashboard")).toBe(false);
    expect(hiddenRouteNames.value.size).toBe(0);
  });

  /*
   * A document from a node that predates the field, or a cached one written by
   * an older build. The defaults are the same answer the node gives somebody
   * who has decided nothing, so the menu does not change shape depending on
   * which build last wrote the cache.
   */
  it("falls back to the catalog defaults when the document carries none", () => {
    installClientSession(
      fixtureSessionDocument({ preferences: undefined }),
      "network",
    );

    expect([...hiddenPageKeys.value]).toEqual(
      HIDEABLE_PAGES.filter((page) => page.hiddenByDefault).map(
        (page) => page.key,
      ),
    );
  });

  it("drops the links it names and keeps every other one", () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );

    const links = [
      { label: "Me", to: { name: "staff.me" } },
      { label: "Dashboard", to: { name: "staff.dashboard" } },
      { label: "Shifts", to: { name: "staff.shifts.index" } },
    ];

    expect(visibleLinks(links).map((link) => link.label)).toEqual([
      "Me",
      "Shifts",
    ]);
  });
});

describe("changing what is hidden", () => {
  it("tells the node and settles on the answer it gives back", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );

    const fetchMock = respondWith([]);

    await setPageHidden("dashboard", false);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

    expect(String(url)).toContain("/api/commands/set-page-visibility");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({
      page_key: "dashboard",
      hidden: false,
    });
    expect(pageHidden("dashboard")).toBe(false);
  });

  /*
   * The node's answer wins over the click. A reader whose write is applied
   * differently from how they asked — because another device got there first,
   * or because the catalog moved — sees what their account actually holds.
   */
  it("prefers the node's list to the one the click implied", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: [] } }),
      "network",
    );

    respondWith(["dashboard"]);

    await setPageHidden("dashboard", false);

    expect(pageHidden("dashboard")).toBe(true);
  });

  /*
   * Connected-only, because the preference lives on the account. A queued
   * change is a menu that disagrees with itself on every other device until
   * the queue drains, so the refusal is raised where the surface can say it
   * rather than swallowed.
   */
  it("refuses on a device with no node, and leaves the menu as it was", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );

    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    recordNodeUnreachable();

    await expect(setPageHidden("dashboard", false)).rejects.toThrow(
      ConnectedOnlyCommandError,
    );

    expect(fetchMock).not.toHaveBeenCalled();
    expect(pageHidden("dashboard")).toBe(true);
  });

  it("leaves the menu as it was when the node refuses the write", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("{}", { status: 422 })),
    );

    await expect(setPageHidden("dashboard", false)).rejects.toThrow();

    expect(pageHidden("dashboard")).toBe(true);
  });

  /*
   * A fresh session document is the node saying it again, so the answer this
   * device is holding onto is released. Otherwise a preference changed on
   * another device would never reach this one.
   */
  it("gives way to a newer session document", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
    );

    respondWith([]);
    await setPageHidden("dashboard", false);
    expect(pageHidden("dashboard")).toBe(false);

    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: ["dashboard"] },
        refreshed_at: "2026-09-11T19:00:00+00:00",
      }),
      "network",
    );

    expect(pageHidden("dashboard")).toBe(true);
  });
});
