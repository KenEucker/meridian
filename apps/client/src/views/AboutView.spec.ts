import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { resetNodeReachability } from "@/offline/nodeReachability";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";

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
