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
import { routes } from "@/router";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";
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

function readyCount(wrapper: VueWrapper): number {
  const text = wrapper.get(".about__readiness-count").text();

  return Number(text.split("/")[0]);
}
