import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { configureMeridianApi } from "@/api/meridianApi";
import { clearFieldSession } from "@/field-reports/fieldSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
} from "@/session/localFieldSessionFixture";
import { routes } from "@/router";
import { adoptHeldApiToken } from "@/session/apiLogin";
import { clearApiToken, storeApiToken } from "@/session/apiToken";
import { clearClientSession } from "@/session/clientSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
} from "@/session/sessionAccess";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

afterEach(() => {
  configureMeridianApi(null);
  clearFieldSession();
  clearClientSession();
  clearApiToken();
  adoptHeldApiToken();
  resetSelectedSessionDepartment();
  vi.unstubAllGlobals();
});

describe("shared client shell", () => {
  it("renders the app shell with the operations home dashboard", async () => {
    installLocalFieldSession();
    selectSessionDepartment(LOCAL_FIELD_DEPARTMENT_IDS.rangers);

    const router = buildRouter();
    await router.push("/");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });

    expect(wrapper.find(".app-shell").exists()).toBe(true);
    expect(wrapper.get(".route-loader").attributes("aria-label")).toBe(
      "Loading page data",
    );
    expect(wrapper.get("#home-heading").text()).toBe("Local Field Event");
    expect(wrapper.text()).toContain("Rangers operations workspace.");
    expect(wrapper.text()).toContain("Overview");
    expect(wrapper.text()).toContain("Logistics");
    expect(wrapper.text()).toContain("Operations Center");
    expect(wrapper.text()).toContain("Readiness");
    expect(document.title).toBe("Meridian Admin");
  });

  it("renders the not-found placeholder for unknown routes", async () => {
    const router = buildRouter();
    await router.push("/does-not-exist");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });

    expect(wrapper.get("#not-found-heading").text()).toBe("Page not found");
  });

  it("renders the settings/about route with the client version", async () => {
    configureMeridianApi({
      baseUrl: "http://localhost:8000",
      bearerToken: null,
    });
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        new Response(
          JSON.stringify({
            status: "ok",
            environment: "local",
            server_version: "1.2.3",
            config_schema_version: 1,
            timestamp: "2026-07-18T12:00:00.000Z",
          }),
          {
            status: 200,
            headers: { "content-type": "application/json" },
          },
        ),
      ),
    );

    const router = buildRouter();
    await router.push("/settings/about");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });
    await flushPromises();

    expect(wrapper.get("#about-heading").text()).toBe("Settings");
    expect(wrapper.get('[aria-label="Version metadata"]').text()).toContain(
      "Client bundle version",
    );
    expect(wrapper.get('[aria-label="Operational health"]').text()).toContain(
      "Server health",
    );
    expect(wrapper.get('[aria-label="Operational health"]').text()).toContain(
      "Reachable (ok); server 1.2.3.",
    );
    expect(wrapper.get('[aria-label="Operational health"]').text()).toContain(
      "Not signed in; commands are held on this device.",
    );
  });

  it("renders command diagnostics for a signed-in device on the settings/about route", async () => {
    storeApiToken({
      token: "device-token",
      user: {
        id: LOCAL_FIELD_FIXTURE.submittedByUserId,
        name: "Local Field Author",
        email: "local-field-author@example.test",
      },
    });
    adoptHeldApiToken();
    configureMeridianApi({
      baseUrl: "http://localhost:8000",
      bearerToken: "device-token",
    });
    installLocalFieldSession();
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        new Response(
          JSON.stringify({
            status: "ok",
            environment: "local",
            server_version: "1.2.3",
            config_schema_version: 1,
            timestamp: "2026-07-18T12:00:00.000Z",
          }),
          {
            status: 200,
            headers: { "content-type": "application/json" },
          },
        ),
      ),
    );

    const router = buildRouter();
    await router.push("/settings/about");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });
    await flushPromises();

    const healthText = wrapper.get('[aria-label="Operational health"]').text();
    expect(healthText).toContain("Signed in as Local Field Author");
    expect(healthText).toContain("Local Field Event");
    expect(healthText).not.toContain("null");
    expect(healthText).toContain("Ready; no pending Field Report work.");
  });
});
