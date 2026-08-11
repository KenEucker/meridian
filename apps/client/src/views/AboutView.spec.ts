import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { resetNodeReachability } from "@/offline/nodeReachability";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSessionFixture";

/*
 * Device diagnostics reports one device's standing against one node, and it was
 * reporting two irreconcilable things about it.
 *
 * Operational health showed "Online - Central or expected sync target
 * reachable." from `navigator.onLine`, which says only that the device has a
 * network interface up. Two rows below, the health probe -- the one thing on the
 * page that actually asks the node anything -- showed "Failed to fetch". A
 * developer running the client with the server stopped saw both at once.
 *
 * These tests are about the two lines agreeing, because that is what a
 * diagnostics page is for. Its whole value is that somebody can believe it.
 */

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

async function mountDiagnostics() {
  const router = buildRouter();
  await router.push("/settings/about");
  await router.isReady();

  const wrapper = mount(App, { global: { plugins: [router] } });
  await flushPromises();

  return wrapper;
}

function operationalHealth(wrapper: ReturnType<typeof mount>): string {
  return wrapper.get('[aria-label="Operational health"]').text();
}

beforeEach(() => {
  installLocalFieldSession();
  resetNodeReachability();
});

afterEach(() => {
  clearClientSession();
  resetNodeReachability();
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  vi.unstubAllGlobals();
});

describe("Device diagnostics operational health", () => {
  it("does not claim the sync target is reachable while the node is not answering", async () => {
    // The device has a network throughout. That is the case: wifi is fine and
    // Meridian is not running, which is every local development session with the
    // server stopped and every on-site device whose node has gone down.
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const wrapper = await mountDiagnostics();
    const health = operationalHealth(wrapper);

    expect(health).toContain("No node reachable");
    expect(health).toContain("This device cannot reach its node.");
    expect(health).not.toContain("Central or expected sync target reachable");
    expect(health).not.toContain("Connected and fully capable");
  });

  it("agrees with its own health probe", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const wrapper = await mountDiagnostics();
    const health = operationalHealth(wrapper);

    // Both lines describe the same failure rather than contradicting each other.
    expect(health).toContain("Failed to fetch");
    expect(health).toContain("No node reachable");
  });

  it("reports the node as connected once it answers", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({
              status: "ok",
              environment: "local",
              server_version: "1.2.3",
              config_schema_version: 1,
              timestamp: "2026-08-03T12:00:00.000Z",
            }),
            { status: 200, headers: { "content-type": "application/json" } },
          ),
      ),
    );

    const wrapper = await mountDiagnostics();
    const health = operationalHealth(wrapper);

    expect(health).toContain("Connected and fully capable");
    expect(health).toContain("Central or expected sync target reachable");
  });
});

/*
 * Version metadata on the one screen a person is told to read out (M19.1;
 * technical spec 26.3). The composition itself is unit tested in
 * `app/appVersions.spec.ts`; these are about the section rendering what this
 * device and its node actually hold, in both states of the node.
 */
describe("Device diagnostics versions", () => {
  function versions(wrapper: ReturnType<typeof mount>): string {
    return wrapper.get('[aria-label="Version metadata"]').text();
  }

  it("reports the build, the wrapper, and the node's versions together", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({
              status: "ok",
              environment: "local",
              server_version: "1.2.3",
              config_schema_version: 4,
              timestamp: "2026-08-03T12:00:00.000Z",
            }),
            { status: 200, headers: { "content-type": "application/json" } },
          ),
      ),
    );
    window.__MERIDIAN_RUNTIME_CONFIG__ = { desktopAppVersion: "9.9.9" };

    const wrapper = await mountDiagnostics();
    const rendered = versions(wrapper);

    expect(rendered).toContain("Desktop app version");
    expect(rendered).toContain("9.9.9");
    expect(rendered).toContain("Client bundle version");
    expect(rendered).toContain("0.0.0-test");
    expect(rendered).toContain("Server version");
    expect(rendered).toContain("1.2.3");
    expect(rendered).toContain("Config schema version");
    expect(rendered).toContain("4");
  });

  it("still reports this device's own versions with the node down", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const wrapper = await mountDiagnostics();
    const rendered = versions(wrapper);

    expect(rendered).toContain("Client bundle version");
    expect(rendered).toContain("0.0.0-test");
    // The two server rows are the only ones that go unavailable, and they say so
    // rather than showing a stale or invented version.
    expect(rendered).toContain("Server version");
    expect(rendered).toContain("Unavailable");
    expect(rendered).not.toContain("Desktop app version");
  });
});
