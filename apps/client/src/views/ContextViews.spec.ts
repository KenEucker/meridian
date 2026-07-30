import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { routes } from "@/router";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  installLocalFieldSession,
  localFieldSessionDocument,
  LOCAL_FIELD_ORGANIZATION_ID,
  LOCAL_FIELD_OTHER_EVENT_ID,
  LOCAL_FIELD_OTHER_ORGANIZATION_ID,
  switchableLocalFieldContext,
} from "@/session/localFieldSession";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import type { SessionDocument } from "@/session/sessionDocument";
import EventContextView from "@/views/EventContextView.vue";
import HomeView from "@/views/HomeView.vue";
import OrganizationContextView from "@/views/OrganizationContextView.vue";

/*
 * The two context-switching surfaces (M16.7; CLIENT-011 through CLIENT-014;
 * UI implementation contract 12.2, 19A.3).
 *
 * Both are always routable and both refuse for themselves, so what is asserted
 * here is what each says in each state rather than whether the route exists.
 */

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

function installSwitchableSession(source: "network" | "cache" = "network") {
  installClientSession(
    localFieldSessionDocument(switchableLocalFieldContext()),
    source,
  );
}

function switchedDocument(): SessionDocument {
  const switchable = switchableLocalFieldContext();

  return localFieldSessionDocument({
    ...switchable,
    context: {
      ...switchable.context,
      organization_id: LOCAL_FIELD_OTHER_ORGANIZATION_ID,
      event_id: LOCAL_FIELD_OTHER_EVENT_ID,
      department_id: null,
    },
    departments: [],
    teams: [],
    roles: [],
    capabilities: [],
  });
}

function setDeviceOnLine(value: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
  window.dispatchEvent(new Event(value ? "online" : "offline"));
}

async function mountEventContext(organizationId: string) {
  const router = buildRouter();
  await router.push({
    name: "organizations.events.index",
    params: { organizationId },
  });
  await router.isReady();

  return {
    router,
    wrapper: mount(EventContextView, { global: { plugins: [router] } }),
  };
}

beforeEach(() => {
  setDeviceOnLine(true);
  installLocalFieldSession();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  configureMeridianApi(null);
  clearClientSession();
  resetSelectedSessionDepartment();
  setDeviceOnLine(true);
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("context.organizations", () => {
  it("lists the organizations the session carries and marks the current one", async () => {
    installSwitchableSession();

    const router = buildRouter();
    await router.push({ name: "organizations.index" });
    await router.isReady();

    const wrapper = mount(OrganizationContextView, {
      global: { plugins: [router] },
    });
    const items = wrapper.findAll(".context-organizations__item");

    expect(items).toHaveLength(2);
    expect(items[0]!.text()).toContain("Idaho Burners");
    expect(items[0]!.attributes("data-current")).toBe("true");
    expect(items[1]!.text()).toContain("Cascadia Collective");
    expect(items[1]!.attributes("data-current")).toBe("false");
    expect(wrapper.findAll(".context-organizations__enter")).toHaveLength(2);
  });

  it("says why there is nothing to choose on a node locked to an event", async () => {
    // Absent rather than disabled, with the reason stated so the choice does
    // not look like one the client lost (operating guide 8.3A).
    const router = buildRouter();
    await router.push({ name: "organizations.index" });
    await router.isReady();

    const wrapper = mount(OrganizationContextView, {
      global: { plugins: [router] },
    });

    expect(
      wrapper.get(".context-organizations__unavailable").attributes("data-reason"),
    ).toBe("node_locked");
    expect(wrapper.get(".context-organizations__unavailable").text()).toContain(
      "Local Field Event",
    );
    expect(wrapper.findAll(".context-organizations__enter")).toHaveLength(0);
  });
});

describe("context.events", () => {
  it("lists the organization's events and offers every one but the current", async () => {
    installSwitchableSession();

    const { wrapper } = await mountEventContext(LOCAL_FIELD_ORGANIZATION_ID);

    expect(wrapper.text()).toContain("Idaho Burners");
    const items = wrapper.findAll(".context-events__item");
    expect(items).toHaveLength(1);
    expect(items[0]!.attributes("data-current")).toBe("true");
    // Switching to where you already are is a control with nothing to do.
    expect(wrapper.findAll(".context-events__switch")).toHaveLength(0);
  });

  it("switches the client into the chosen event and sends it home", async () => {
    // CLIENT-012, CLIENT-014: the node resolves the session at the named event,
    // and permissions, navigation, and organization all follow the document it
    // answers with.
    installSwitchableSession();

    const fetchMock = vi.fn(
      async (_input: RequestInfo | URL, _init?: RequestInit) =>
        new Response(JSON.stringify(switchedDocument()), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const { router, wrapper } = await mountEventContext(
      LOCAL_FIELD_OTHER_ORGANIZATION_ID,
    );

    await wrapper.get(".context-events__switch").trigger("click");
    await flushPromises();

    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      `http://node.test/api/me?event_id=${LOCAL_FIELD_OTHER_EVENT_ID}`,
    );
    // Home rather than back: the screen the user came from was a screen of the
    // previous context.
    expect(router.currentRoute.value.name).toBe("home");
  });

  it("keeps the client where it was when the switch cannot reach the node", async () => {
    installSwitchableSession();
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    const { router, wrapper } = await mountEventContext(
      LOCAL_FIELD_OTHER_ORGANIZATION_ID,
    );

    await wrapper.get(".context-events__switch").trigger("click");
    await flushPromises();

    expect(wrapper.get(".context-events__failure").text()).toContain(
      "The node could not be reached",
    );
    expect(router.currentRoute.value.name).toBe("organizations.events.index");
  });

  it("offers no switch to a client with no connectivity", async () => {
    // CLIENT-013: an offline client is locked to the context the node provides.
    installSwitchableSession();
    setDeviceOnLine(false);

    const { wrapper } = await mountEventContext(
      LOCAL_FIELD_OTHER_ORGANIZATION_ID,
    );

    expect(wrapper.findAll(".context-events__switch")).toHaveLength(0);
    expect(
      wrapper.get(".context-events__unavailable").attributes("data-reason"),
    ).toBe("disconnected");
  });

  it("says so when the session holds no association with the organization", async () => {
    installSwitchableSession();

    const { wrapper } = await mountEventContext("org-somebody-elses");

    expect(wrapper.text()).toContain(
      "This session carries no association with that organization",
    );
    expect(wrapper.findAll(".context-events__switch")).toHaveLength(0);
  });
});

describe("context screens on Home", () => {
  it("lists both context screens for a client that may switch", async () => {
    // Contract rule 4.4: switching happens on Home or a dedicated surface.
    installSwitchableSession();

    const router = buildRouter();
    await router.push({ name: "home" });
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [router] } });
    const cards = wrapper.findAll(".home__card h3").map((card) => card.text());

    expect(cards).toContain("Switch organization");
    expect(cards).toContain("Switch event");
  });

  it("lists neither for a client locked to its node's context", async () => {
    const router = buildRouter();
    await router.push({ name: "home" });
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [router] } });
    const cards = wrapper.findAll(".home__card h3").map((card) => card.text());

    expect(cards).not.toContain("Switch organization");
    expect(cards).not.toContain("Switch event");
  });
});
