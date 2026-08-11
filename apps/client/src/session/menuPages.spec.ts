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
  MENU_PAGES,
  menuHiddenPageKeys,
  menuHiddenRouteNames,
  menuLinks,
  menuPageKeyFor,
  pageInMenu,
  resetMenuPageAnswers,
  setPageInMenu,
} from "@/session/menuPages";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

/*
 * Pages the reader keeps out of their menus (M18.69).
 *
 * No server runs here (CLIENT-024). The write is connected-only, so it is
 * exercised against a stubbed `fetch` and against a device with no node.
 *
 * The property worth stating up front is the one that separates this from
 * `hiddenPages` next door: this preference shortens a menu and nothing else.
 * Every route it names stays reachable, stays on Home, and stays enforced on
 * arrival — the filtering here is subtraction from a list the capability checks
 * already built.
 */

function setDeviceOnLine(onLine: boolean): void {
  Object.defineProperty(globalThis.navigator, "onLine", {
    configurable: true,
    get: () => onLine,
  });
  globalThis.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

function respondWith(pages: readonly string[]): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(
    async () =>
      new Response(JSON.stringify({ menu_hidden_pages: pages }), {
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
  resetMenuPageAnswers();
  setDeviceOnLine(true);
});

afterEach(() => {
  clearClientSession();
  resetMenuPageAnswers();
  resetNodeReachability();
  resetCentralReachability();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("what the session says is out of the menus", () => {
  it("reads the node's answer off the session document", () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: ["logistics"] },
      }),
      "network",
    );

    expect(pageInMenu("logistics")).toBe(false);
    expect(pageInMenu("planning")).toBe(true);
    expect([...menuHiddenRouteNames.value]).toEqual([
      "events.departments.logistics",
    ]);
  });

  /*
   * A document from a node that predates the field, or a cached one written by
   * an older build. The reader gets the catalog's defaults — every hub page in,
   * every promotable one out — rather than a menu whose shape depends on which
   * build last wrote the cache.
   */
  it("falls back to the catalog defaults when the document carries none", () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: [] } }),
      "network",
    );

    expect([...menuHiddenPageKeys.value].sort()).toEqual(
      MENU_PAGES.filter((page) => page.hiddenByDefault)
        .map((page) => page.key)
        .sort(),
    );
    expect(pageInMenu("logistics")).toBe(true);
    expect(pageInMenu("acknowledgments")).toBe(false);
  });

  it("drops the links it names and keeps every other one", () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: ["dashboard"] },
      }),
      "network",
    );

    const links = [
      { label: "Me", to: { name: "staff.me" } },
      { label: "Dashboard", to: { name: "staff.dashboard" } },
      { label: "Dashboard", to: { name: "events.departments.show" } },
      { label: "Shifts", to: { name: "staff.shifts.index" } },
    ];

    expect(menuLinks(links).map((link) => link.label)).toEqual([
      "Me",
      "Shifts",
    ]);
  });

  /*
   * Home lists pages the menus never carry. There is nothing to offer a reader
   * about the menu position of a page that has none, so the lookup says so
   * rather than inventing a key nothing would store.
   */
  it("has no key for a page the menus do not carry", () => {
    // Department and organization administration is not promotable, so these
    // are Home pages with no menu question to answer.
    expect(menuPageKeyFor("events.departments.roster")).toBeNull();
    expect(menuPageKeyFor("organizer.audit.index")).toBeNull();
    expect(menuPageKeyFor("events.departments.logistics")).toBe("logistics");
    // The four that are promotable do have one, whether or not they are in a
    // menu right now.
    expect(menuPageKeyFor("staff.documents.acknowledgments")).toBe(
      "acknowledgments",
    );
    expect(menuPageKeyFor("ims.dashboard")).toBe("ic-dashboard");
  });

  /*
   * The keys are a vocabulary shared with `MenuPageCatalog` on the node, which
   * validates every write against its own copy. A key here that the node does
   * not know is a control that cannot be used, so a route may only ever appear
   * under one of them.
   */
  it("gives every route exactly one key", () => {
    const routes = MENU_PAGES.flatMap((page) => page.routeNames);

    expect(new Set(routes).size).toBe(routes.length);
    expect(new Set(MENU_PAGES.map((page) => page.key)).size).toBe(
      MENU_PAGES.length,
    );
  });
});

describe("changing what the menus carry", () => {
  it("tells the node and settles on the answer it gives back", async () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: [] },
      }),
      "network",
    );

    const fetchMock = respondWith(["planning"]);

    await setPageInMenu("planning", false);

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

    expect(String(url)).toContain("/api/commands/set-menu-page-visibility");
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({
      page_key: "planning",
      hidden: true,
    });
    expect(pageInMenu("planning")).toBe(false);
  });

  it("puts a page back", async () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: ["planning"] },
      }),
      "network",
    );

    const fetchMock = respondWith([]);

    await setPageInMenu("planning", true);

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];

    expect(JSON.parse(String(init.body))).toEqual({
      page_key: "planning",
      hidden: false,
    });
    expect(pageInMenu("planning")).toBe(true);
  });

  /*
   * Connected-only, because the preference lives on the account. A queued
   * change is a menu that disagrees with itself on every other device until
   * the queue drains, so the refusal is raised where Settings can say it
   * rather than swallowed.
   */
  it("refuses on a device with no node, and leaves the menu as it was", async () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: [] },
      }),
      "network",
    );

    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    recordNodeUnreachable();

    await expect(setPageInMenu("planning", false)).rejects.toThrow(
      ConnectedOnlyCommandError,
    );

    expect(fetchMock).not.toHaveBeenCalled();
    expect(pageInMenu("planning")).toBe(true);
  });

  it("leaves the menu as it was when the node refuses the write", async () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: [] },
      }),
      "network",
    );

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("{}", { status: 422 })),
    );

    await expect(setPageInMenu("planning", false)).rejects.toThrow();

    expect(pageInMenu("planning")).toBe(true);
  });

  /*
   * A fresh session document is the node saying it again, so the answer this
   * device is holding onto is released. Otherwise a preference changed on
   * another device would never reach this one.
   */
  it("gives way to a newer session document", async () => {
    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: [] },
      }),
      "network",
    );

    respondWith(["planning"]);
    await setPageInMenu("planning", false);
    expect(pageInMenu("planning")).toBe(false);

    installClientSession(
      fixtureSessionDocument({
        preferences: { hidden_pages: [], menu_hidden_pages: [] },
        refreshed_at: "2026-09-11T19:00:00+00:00",
      }),
      "network",
    );

    expect(pageInMenu("planning")).toBe(true);
  });
});
