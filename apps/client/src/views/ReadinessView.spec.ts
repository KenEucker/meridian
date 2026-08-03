import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import ReadinessView from "@/views/ReadinessView.vue";
import { routes } from "@/router";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSessionFixture";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

async function mountAt(path: string) {
  const router = buildRouter();
  await router.push(path);
  await router.isReady();

  return mount(App, {
    global: {
      plugins: [router],
    },
  });
}

// The home directory follows the session response (M16.6).
beforeEach(() => {
  installLocalFieldSession();
});

afterEach(() => {
  clearClientSession();
});

describe("readiness surface", () => {
  it("is reachable at the /readiness route", async () => {
    const wrapper = await mountAt("/readiness");

    expect(wrapper.get("#readiness-heading").text()).toBe("Device readiness");
    expect(wrapper.get(".readiness__nav a").attributes("href")).toBe("/");
  });

  it("renders every section 14 readiness item", async () => {
    const wrapper = await mountAt("/readiness");

    const rows = wrapper.findAll(".readiness-checklist__item");
    expect(rows).toHaveLength(8);
    const text = wrapper.text();
    for (const label of [
      "Logged in",
      "Device trusted",
      "Event selected",
      "Local cache complete",
      "Encryption active",
      "Last sync completed",
      "Trusted server known",
      "Device signing available",
    ]) {
      expect(text).toContain(label);
    }
  });

  it("orders readiness cards by ready, failed, and pending", async () => {
    const wrapper = await mountAt("/readiness");

    const statuses = wrapper
      .findAll(".readiness-checklist__item")
      .map((row) => row.attributes("data-status"));

    const order = { ready: 0, "not-ready": 1, pending: 2 };
    const rankFor = (status: string | undefined) =>
      order[status as keyof typeof order] ?? Number.POSITIVE_INFINITY;

    expect(
      statuses.every((status, index) => {
        const previous = statuses[index - 1];
        return index === 0 || rankFor(previous) <= rankFor(status);
      }),
    ).toBe(true);
  });

  it("states that readiness is advisory and user-private, not organizer-visible", async () => {
    const wrapper = await mountAt("/readiness");

    const lede = wrapper.get(".readiness__lede").text();
    expect(lede).toContain("only for you");
    expect(lede).toContain("advisory");
    expect(lede).toContain("not shared with");
    expect(lede).toContain("not required");
  });

  it("summarizes ready checks out of the total", async () => {
    const wrapper = await mountAt("/readiness");

    expect(wrapper.get(".readiness__summary").text()).toMatch(
      /^\d+ of 8 checks ready\.$/,
    );
  });

  it("links to the readiness surface from the home dashboard", async () => {
    const wrapper = await mountAt("/");

    const link = wrapper
      .findAll(".home__card")
      .find((item) => item.text().includes("Readiness"));

    expect(link?.text()).toContain("Readiness");
    expect(link?.attributes("href")).toBe("/readiness");
  });

  it("probes the current client scope when the surface renders", () => {
    const wrapper = mount(ReadinessView, {
      global: {
        stubs: {
          RouterLink: { template: "<a><slot /></a>" },
        },
      },
    });

    expect(wrapper.findAll(".readiness-checklist__item")).toHaveLength(8);
  });
});
