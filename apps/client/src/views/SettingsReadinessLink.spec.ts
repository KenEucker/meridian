// The way into device readiness from Settings.
//
// Readiness had its own screen, its own route, and nothing anywhere that linked
// to it — a page reachable by knowing the URL. The count rides on the link
// because "5 of 8 ready" is the whole answer most of the time, and the screen is
// for the times it is not (technical spec 14).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { COMBINED_NAVIGATION_MAX_ITEMS } from "@/components/workflowLinks";
import { routes } from "@/router";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSessionFixture";
import { resetHiddenPageAnswers } from "@/session/hiddenPages";
import { resetMenuPageAnswers } from "@/session/menuPages";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import {
  recordNodeUnreachable,
  resetNodeReachability,
} from "@/offline/nodeReachability";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";
import AboutView from "@/views/AboutView.vue";

const mounted: VueWrapper[] = [];

async function mountSettings(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push("/settings/about");
  await router.isReady();

  const wrapper = mount(AboutView, { global: { plugins: [router] } });

  mounted.push(wrapper);
  await flushPromises();

  return wrapper;
}

beforeEach(() => {
  // The node is stubbed only so the health panel's own fetch resolves; nothing
  // here depends on its answer.
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify({ status: "ok" }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
    ),
  );
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  clearClientSession();
  resetSelectedSessionDepartment();
  resetHiddenPageAnswers();
  resetMenuPageAnswers();
  resetNodeReachability();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
  window.localStorage.clear();
});

describe("the readiness link on Settings", () => {
  it("links to device readiness and counts what is ready", async () => {
    const wrapper = await mountSettings();
    const link = wrapper.get(".about__readiness-link");

    expect(link.attributes("href")).toBe("/readiness");
    expect(link.text()).toContain("Device readiness");
    expect(link.get(".about__readiness-count").text()).toMatch(
      /^\d+\/\d+ ready$/,
    );
  });

  it("counts more items ready once a session is established", async () => {
    const before = await mountSettings();
    const signedOut = readyCount(before);

    installLocalFieldSession();

    const after = await mountSettings();

    // Signing in answers `logged in` and `event selected`, which is two more
    // than a device holding no session can report.
    expect(readyCount(after)).toBe(signedOut + 2);
  });

  it("marks the count complete only when every check is ready", async () => {
    const wrapper = await mountSettings();
    const count = wrapper.get(".about__readiness-count");

    // Three items have no signal in this build, so a jsdom device is never
    // complete — and the count says the number either way rather than relying
    // on the colour to carry it.
    expect(count.attributes("data-complete")).toBe("false");
    expect(count.text()).toContain("ready");
  });
});

/*
 * Cached-permission state on Settings (CLIENT-009; contract 19A.2).
 *
 * It used to sit in the app shell, on every screen. It now lives here and only
 * here, so this is where the states have to be readable — including the state
 * where nothing is wrong, because a section that renders nothing whenever the
 * answer is "fine" reads as broken at the moment it is working.
 */
describe("cached-permission state on Settings", () => {
  it("reports current permissions and offers a refresh", async () => {
    installClientSession(
      fixtureSessionDocument(),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();
    const notice = wrapper.get(".about__permissions .session-permissions");

    expect(wrapper.text()).toContain("Permissions");
    expect(notice.attributes("data-session-status")).toBe("live");
    expect(notice.text()).toContain("Permissions are current");
    expect(notice.text()).toContain("Last refreshed");
    expect(notice.get("button").text()).toBe("Refresh permissions");
  });

  it("reports a cached session as cached", async () => {
    installClientSession(
      fixtureSessionDocument(),
      "cache",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();
    const notice = wrapper.get(".about__permissions .session-permissions");

    expect(notice.attributes("data-session-status")).toBe("cached");
    expect(notice.text()).toContain("Permissions are cached");
  });

  it("says there is nothing to report when the device holds no session", async () => {
    const wrapper = await mountSettings();

    expect(wrapper.find(".about__permissions .session-permissions").exists()).toBe(
      false,
    );
    expect(wrapper.get(".about__permissions-empty").text()).toContain(
      "This device holds no session",
    );
  });
});

/*
 * Which pages appear, from Settings (M18.69).
 *
 * The section is the only way to reach the preference, so what has to be
 * readable here is the state on arrival, the write, and — the one that costs a
 * reader real time when it is missing — the refusal. The setting lives on the
 * account, so a device with no node cannot change it, and a control that
 * silently snapped back would leave them clicking it again.
 */
describe("page visibility on Settings", () => {
  it("shows the state the session holds", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();
    const [show, hide] = wrapper
      .get(".about__pages")
      .findAll(".about__theme-toggle button");

    expect(show.text()).toBe("Show");
    expect(show.attributes("aria-pressed")).toBe("false");
    expect(hide.attributes("aria-pressed")).toBe("true");
  });

  it("writes the change to the node and follows its answer", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();

    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify({ hidden_pages: [] }), {
            status: 200,
            headers: { "Content-Type": "application/json" },
          }),
      ),
    );

    await wrapper.get(".about__pages").findAll(".about__theme-toggle button")[0].trigger("click");
    await flushPromises();

    const [show, hide] = wrapper
      .get(".about__pages")
      .findAll(".about__theme-toggle button");

    expect(show.attributes("aria-pressed")).toBe("true");
    expect(hide.attributes("aria-pressed")).toBe("false");
    expect(wrapper.find(".about__pages-error").exists()).toBe(false);
  });

  it("says so when the change cannot be made", async () => {
    installClientSession(
      fixtureSessionDocument({ preferences: { hidden_pages: ["dashboard"] } }),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();

    recordNodeUnreachable();

    await wrapper.get(".about__pages").findAll(".about__theme-toggle button")[0].trigger("click");
    await flushPromises();

    expect(wrapper.get(".about__pages-error").text()).toContain(
      "needs a connection to the node",
    );
    // And the control still says what the account holds, rather than the state
    // the click asked for and did not get.
    expect(
      wrapper
        .get(".about__pages")
        .findAll(".about__theme-toggle button")[1]
        .attributes("aria-pressed"),
    ).toBe("true");
  });
});

/*
 * What the menus carry, from Settings (M18.69).
 *
 * The rows are the reader's own menu entries, so what has to be readable here
 * is that they are built from the session rather than from a fixed list, that
 * the control says which side it is on, and that a refusal is said out loud —
 * the same three things the Pages section owes, for a preference stored the
 * same way.
 */
describe("menu contents on Settings", () => {
  function sessionWithMenu(menuHidden: readonly string[]) {
    return fixtureSessionDocument({
      preferences: { hidden_pages: [], menu_hidden_pages: menuHidden },
    });
  }

  function boxes(wrapper: VueWrapper) {
    return wrapper.get(".about__menus").findAll("input[type=checkbox]");
  }

  function labels(wrapper: VueWrapper): string[] {
    return wrapper
      .get(".about__menus")
      .findAll(".about__menus-list li")
      .map((row) => row.text());
  }

  it("lists the reader's own menu entries and ticks the ones in it", async () => {
    installClientSession(
      sessionWithMenu(["shift-board"]),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();
    const rowLabels = labels(wrapper);

    expect(rowLabels).toContain("Me");
    expect(rowLabels).toContain("Shift Board");
    // One row per page rather than one per route: the dashboards are one page
    // seen from several standings.
    expect(new Set(rowLabels).size).toBe(rowLabels.length);

    const shiftBoard = boxes(wrapper)[rowLabels.indexOf("Shift Board")];

    expect((shiftBoard.element as HTMLInputElement).checked).toBe(false);
    expect((boxes(wrapper)[rowLabels.indexOf("Me")].element as HTMLInputElement).checked).toBe(
      true,
    );
  });

  /* The count is the thing being decided, so it is said rather than left to be
     counted off the boxes. */
  it("says how much of the menu is spent", async () => {
    installClientSession(
      sessionWithMenu(["shift-board"]),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();
    const chosen = boxes(wrapper).filter(
      (box) => (box.element as HTMLInputElement).checked,
    ).length;

    expect(wrapper.get(".about__menus-count").text()).toBe(
      `${chosen} of ${COMBINED_NAVIGATION_MAX_ITEMS} in your menus`,
    );
  });

  it("writes the change to the node and follows its answer", async () => {
    installClientSession(
      sessionWithMenu([]),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();

    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify({ menu_hidden_pages: ["me"] }), {
            status: 200,
            headers: { "Content-Type": "application/json" },
          }),
      ),
    );

    const me = boxes(wrapper)[labels(wrapper).indexOf("Me")];

    (me.element as HTMLInputElement).checked = false;
    await me.trigger("change");
    await flushPromises();

    expect(
      (boxes(wrapper)[labels(wrapper).indexOf("Me")].element as HTMLInputElement)
        .checked,
    ).toBe(false);
    expect(wrapper.find(".about__menus-error").exists()).toBe(false);
  });

  it("says so when the change cannot be made", async () => {
    installClientSession(
      sessionWithMenu([]),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = await mountSettings();

    recordNodeUnreachable();

    const me = boxes(wrapper)[labels(wrapper).indexOf("Me")];

    (me.element as HTMLInputElement).checked = false;
    await me.trigger("change");
    await flushPromises();

    expect(wrapper.get(".about__menus-error").text()).toContain(
      "needs a connection to the node",
    );
    // And the box still says what the account holds, rather than the state the
    // click asked for and did not get.
    expect(
      (boxes(wrapper)[labels(wrapper).indexOf("Me")].element as HTMLInputElement)
        .checked,
    ).toBe(true);
  });

  it("says the menus are empty when the device holds no session", async () => {
    const wrapper = await mountSettings();

    expect(boxes(wrapper)).toEqual([]);
    expect(wrapper.get(".about__menus").text()).toContain(
      "Your menus are empty",
    );
  });
});

function readyCount(wrapper: VueWrapper): number {
  const text = wrapper.get(".about__readiness-count").text();

  return Number(text.split("/")[0]);
}
